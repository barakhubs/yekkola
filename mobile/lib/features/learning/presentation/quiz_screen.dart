import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/sync/sync_providers.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../catalog/presentation/catalog_labels.dart';
import '../data/quiz_repository.dart';
import 'learning_providers.dart';

/// What the course view knows about a quiz before it opens.
class QuizArgs {
  const QuizArgs({this.title, this.facts});

  final String? title;
  final LearningViewResourceSectionsItemLessonsItemQuiz? facts;
}

enum _Stage { loading, intro, taking, waitingForResult, result }

/// A quiz lesson (PRD-06 FR-07, FR-08): the rules, then one question at a time, then the score with the
/// corrections and explanations. Starting needs the network; a started attempt can be finished offline and is
/// graded when it syncs (the answer key never reaches the phone before grading). The time limit, if any,
/// counts down from the API's `seconds_left`, and the API has the last word.
class QuizScreen extends ConsumerStatefulWidget {
  const QuizScreen({super.key, required this.courseId, required this.lessonUid, this.args = const QuizArgs()});

  final String courseId;
  final String lessonUid;
  final QuizArgs args;

  @override
  ConsumerState<QuizScreen> createState() => _QuizScreenState();
}

class _QuizScreenState extends ConsumerState<QuizScreen> {
  _Stage _stage = _Stage.loading;
  LocalAttempt? _attempt;
  QuizAttemptResource? _result;
  String? _refusal;
  ApiException? _error;
  bool _busy = false;
  int _page = 0;
  Timer? _tick;
  Timer? _poll;

  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  QuizRepository get _repo => ref.read(quizRepositoryProvider);

  /// The API's latest facts (attempts used, can start, best score), from the course view; the copy handed in by
  /// the course screen until it loads. Whether another attempt is allowed is always the API's `can_start`.
  LearningViewResourceSectionsItemLessonsItemQuiz? get _facts {
    final view = ref.watch(learnCourseProvider(widget.courseId)).value?.data;
    for (final section in view?.sections ?? const <LearningViewResourceSectionsItem>[]) {
      for (final lesson in section.lessons) {
        if (lesson.uid == widget.lessonUid && lesson.quiz != null) return lesson.quiz;
      }
    }
    return widget.args.facts;
  }

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _tick?.cancel();
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final userId = _userId;
    if (userId == null) return;
    final attempt = await _repo.local(userId, widget.lessonUid);
    final result = await _repo.result(userId, widget.lessonUid);
    final refusal = await _repo.refusal(userId, widget.lessonUid);
    if (!mounted) return;
    setState(() {
      _attempt = attempt;
      _result = result;
      _refusal = refusal;
      _stage = switch ((attempt, result)) {
        (final a?, _) when a.handedIn && refusal == null => _Stage.waitingForResult,
        (final a?, _) when !a.handedIn => _Stage.taking,
        (_, final _?) => _Stage.result,
        _ => _Stage.intro,
      };
    });
    if (_stage == _Stage.taking) _startTick();
    if (_stage == _Stage.waitingForResult) _waitForResult();
  }

  Future<void> _start() async {
    final userId = _userId;
    if (userId == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final attempt = await _repo.start(userId, widget.lessonUid);
      if (!mounted) return;
      setState(() {
        _attempt = attempt;
        _result = null;
        _refusal = null;
        _page = 0;
        _stage = _Stage.taking;
      });
      _startTick();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startTick() {
    _tick?.cancel();
    if (_attempt?.attempt.secondsLeft == null) return;
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() {});
      // Time is up: hand in what there is (the API grades by its own clock anyway).
      if ((_attempt?.secondsLeft(DateTime.now().toUtc()) ?? 1) == 0) unawaited(_handIn());
    });
  }

  Future<void> _choose(QuizQuestionResource question, String optionId, bool selected) async {
    final userId = _userId;
    final attempt = _attempt;
    if (userId == null || attempt == null) return;
    final current = attempt.answers[question.id] ?? const <String>[];
    final next = question.type2 == 'multiple'
        ? (selected ? {...current, optionId}.toList() : current.where((id) => id != optionId).toList())
        : [optionId];
    setState(() => _attempt = attempt.copyWith(answers: {...attempt.answers, question.id: next}));
    await _repo.answer(userId, widget.lessonUid, question.id, next);
  }

  Future<void> _handIn() async {
    final userId = _userId;
    if (userId == null || _stage != _Stage.taking) return;
    _tick?.cancel();
    await _repo.handIn(userId, widget.lessonUid, answers: _attempt?.answers);
    if (!mounted) return;
    setState(() => _stage = _Stage.waitingForResult);
    _waitForResult();
  }

  /// Sends now if online; the result shows as soon as the API has graded it (also after a later sync).
  void _waitForResult() {
    _poll?.cancel();
    unawaited(ref.read(syncEngineProvider).flush());
    _poll = Timer.periodic(const Duration(seconds: 2), (_) async {
      final userId = _userId;
      if (userId == null || !mounted) return;
      final result = await _repo.result(userId, widget.lessonUid);
      final refusal = await _repo.refusal(userId, widget.lessonUid);
      if (!mounted) return;
      if (result != null || refusal != null) {
        _poll?.cancel();
        setState(() {
          _result = result;
          _refusal = refusal;
          _stage = result != null ? _Stage.result : _Stage.intro;
        });
        // Fresh facts from the API: attempts used, best score, whether another attempt is allowed.
        ref.read(learningRefreshProvider.notifier).pull();
      }
    });
  }

  Future<bool> _confirmHandIn(AppLocalizations l10n, int unanswered) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(l10n.quizHandInTitle),
          content: Text(unanswered > 0 ? l10n.quizUnanswered(unanswered) : l10n.quizHandInBody),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.commonCancel)),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(l10n.quizHandIn)),
          ],
        ),
      ) ??
      false;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final attempt = _attempt;
    final left = attempt?.secondsLeft(DateTime.now().toUtc());

    final timed = _stage == _Stage.taking && left != null;

    return PopScope(
      // Leaving a timed quiz: the clock keeps running on the API; ask first.
      canPop: !timed,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop || !await _confirmLeave(l10n) || !context.mounted) return;
        Navigator.of(context).pop();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.args.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
          actions: [
            if (_stage == _Stage.taking && left != null)
              Center(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Semantics(
                    label: l10n.quizTimeLeft(durationLabel(l10n, left)),
                    child: Text(
                      Formatters.clock(left),
                      style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()], fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
              ),
          ],
        ),
        body: SafeArea(
          child: switch (_stage) {
            _Stage.loading => const Center(child: CircularProgressIndicator()),
            _Stage.intro => _intro(l10n),
            _Stage.taking => _question(l10n, attempt!),
            _Stage.waitingForResult => Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const CircularProgressIndicator(),
                    const SizedBox(height: 16),
                    Text(l10n.quizWaitingForResult, textAlign: TextAlign.center),
                  ],
                ),
              ),
            ),
            _Stage.result => _resultView(l10n, _result!),
          },
        ),
      ),
    );
  }

  Future<bool> _confirmLeave(AppLocalizations l10n) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(l10n.quizLeaveTitle),
          content: Text(l10n.quizLeaveBody),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.quizStay)),
            TextButton(onPressed: () => Navigator.pop(context, true), child: Text(l10n.quizLeave)),
          ],
        ),
      ) ??
      false;

  Widget _errorLine(AppLocalizations l10n) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Text(
      _error!.isNetwork ? l10n.quizNeedsNetwork : apiErrorText(l10n, _error!),
      style: TextStyle(color: Theme.of(context).colorScheme.error),
    ),
  );

  Widget _intro(AppLocalizations l10n) {
    final facts = _facts;
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        if (facts != null) ...[
          Text(l10n.quizQuestions(facts.questionCount), style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(l10n.quizPassMark(facts.passMarkPct)),
          if (facts.timeLimitSeconds != null) Text(l10n.quizTimeLimit(durationLabel(l10n, facts.timeLimitSeconds!))),
          if (facts.maxAttempts != null) Text(l10n.quizAttempts(facts.attemptsUsed, facts.maxAttempts!)),
          if (facts.bestScorePct != null) Text(l10n.quizBestScore(facts.bestScorePct!)),
          if (facts.passed) ...[
            const SizedBox(height: 8),
            Text(
              l10n.quizPassed,
              style: TextStyle(color: theme.colorScheme.primary, fontWeight: FontWeight.w700),
            ),
          ],
          const SizedBox(height: 16),
        ],
        if (_refusal != null) ...[
          Text(l10n.quizRefused, style: TextStyle(color: theme.colorScheme.error)),
          const SizedBox(height: 12),
        ],
        if (_error != null) _errorLine(l10n),
        if (facts == null || facts.canStart || facts.openAttemptId != null)
          FilledButton(
            onPressed: _busy ? null : _start,
            child: Text(facts?.openAttemptId != null ? l10n.quizResume : l10n.quizStart),
          )
        else
          Text(l10n.quizNoAttemptsLeft),
        const SizedBox(height: 8),
        // A timed quiz is graded by when the answers reach the API: offline finishing would time out.
        Text(
          facts?.timeLimitSeconds != null ? l10n.quizTimedNote : l10n.quizOfflineNote,
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }

  Widget _question(AppLocalizations l10n, LocalAttempt attempt) {
    final questions = attempt.attempt.questions;
    if (questions.isEmpty) return Center(child: Text(l10n.errorGeneric));
    final index = _page.clamp(0, questions.length - 1);
    final question = questions[index];
    final chosen = attempt.answers[question.id] ?? const <String>[];
    final multiple = question.type2 == 'multiple';
    final last = index == questions.length - 1;
    final unanswered = questions.where((q) => (attempt.answers[q.id] ?? const []).isEmpty).length;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        LinearProgressIndicator(value: (index + 1) / questions.length),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(l10n.quizQuestionOf(index + 1, questions.length), style: Theme.of(context).textTheme.labelMedium),
              const SizedBox(height: 8),
              Text(question.prompt, style: Theme.of(context).textTheme.titleMedium),
              if (multiple) ...[const SizedBox(height: 4), Text(l10n.quizSeveralAnswers)],
              const SizedBox(height: 12),
              if (multiple)
                for (final option in question.options)
                  CheckboxListTile(
                    value: chosen.contains(option.id),
                    title: Text(option.text),
                    onChanged: (on) => _choose(question, option.id, on ?? false),
                  )
              else
                RadioGroup<String>(
                  groupValue: chosen.firstOrNull,
                  onChanged: (id) => id == null ? null : _choose(question, id, true),
                  child: Column(
                    children: [
                      for (final option in question.options)
                        RadioListTile<String>(value: option.id, title: Text(option.text)),
                    ],
                  ),
                ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              if (index > 0)
                OutlinedButton(onPressed: () => setState(() => _page = index - 1), child: Text(l10n.quizPrevious)),
              const Spacer(),
              if (!last)
                FilledButton(onPressed: () => setState(() => _page = index + 1), child: Text(l10n.quizNext))
              else
                FilledButton(
                  onPressed: () async {
                    if (await _confirmHandIn(l10n, unanswered)) await _handIn();
                  },
                  child: Text(l10n.quizHandIn),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _resultView(AppLocalizations l10n, QuizAttemptResource result) {
    final theme = Theme.of(context);
    final passed = result.passed ?? false;
    // The API says whether another attempt is allowed (it refuses one past the limit anyway).
    final canRetry = _facts?.canStart ?? true;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Semantics(
          header: true,
          liveRegion: true,
          child: Column(
            children: [
              Icon(
                passed ? Icons.emoji_events_outlined : Icons.replay,
                size: 48,
                color: passed ? theme.colorScheme.primary : theme.colorScheme.error,
              ),
              const SizedBox(height: 8),
              Text(l10n.quizScore(result.scorePct ?? 0), style: theme.textTheme.headlineMedium),
              Text(
                result.timedOut
                    ? l10n.quizTimedOut
                    : passed
                    ? l10n.quizPassed
                    : l10n.quizNotPassed(result.passMarkPct),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        for (final (i, question) in result.questions.indexed)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        question.answeredCorrectly == true ? Icons.check_circle : Icons.cancel,
                        color: question.answeredCorrectly == true ? theme.colorScheme.primary : theme.colorScheme.error,
                        semanticLabel: question.answeredCorrectly == true ? l10n.quizCorrect : l10n.quizWrong,
                      ),
                      const SizedBox(width: 8),
                      Expanded(child: Text('${i + 1}. ${question.prompt}', style: theme.textTheme.titleSmall)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  for (final option in question.options)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Text(
                        [
                          option.text,
                          if (question.correct?.contains(option.id) ?? false) l10n.quizRightAnswer,
                          if ((question.chosen?.contains(option.id) ?? false)) l10n.quizYourAnswer,
                        ].join(' · '),
                        style: TextStyle(
                          fontWeight: (question.correct?.contains(option.id) ?? false) ? FontWeight.w700 : null,
                        ),
                      ),
                    ),
                  if ((question.explanation ?? '').isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(question.explanation!, style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
            ),
          ),
        const SizedBox(height: 16),
        if (_error != null) _errorLine(l10n),
        if (canRetry)
          FilledButton(onPressed: _busy ? null : _start, child: Text(l10n.quizTryAgain))
        else
          Text(l10n.quizNoAttemptsLeft, textAlign: TextAlign.center),
      ],
    );
  }
}
