import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdfx/pdfx.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/platform/secure_screen.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_providers.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../media/presentation/downloads_controller.dart';
import '../data/document_repository.dart';

/// Documents live next to the downloads, in app-private storage.
final documentRepositoryProvider = Provider<DocumentRepository>(
  (ref) => DocumentRepository(
    api: ref.watch(dioProvider),
    files: ref.watch(segmentDioProvider),
    root: Directory('${ref.watch(downloadsRootProvider).parent.path}${Platform.pathSeparator}documents'),
  ),
);

class DocumentArgs {
  const DocumentArgs({this.title, this.completed = false});

  final String? title;
  final bool completed;
}

/// A document lesson (PRD-06 FR-06): the student's stamped PDF in the in-app viewer, kept on the phone so it
/// opens offline; "mark as done" completes the lesson (synced when online). Screen capture is blocked.
class DocumentScreen extends ConsumerStatefulWidget {
  const DocumentScreen({super.key, required this.lessonUid, this.title, this.completed = false});

  final String lessonUid;
  final String? title;
  final bool completed;

  @override
  ConsumerState<DocumentScreen> createState() => _DocumentScreenState();
}

class _DocumentScreenState extends ConsumerState<DocumentScreen> {
  PdfControllerPinch? _pdf;
  ApiException? _error;
  bool _preparing = false;
  late bool _done = widget.completed;

  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  @override
  void initState() {
    super.initState();
    unawaited(SecureScreen.enable());
    unawaited(_open());
  }

  /// Stops a fetch when the screen closes or the account changes (its documents may have been deleted).
  bool _cancelled(String userId) => !mounted || _userId != userId;

  Future<void> _open({bool refresh = false}) async {
    final userId = _userId;
    if (userId == null) return;
    final repo = ref.read(documentRepositoryProvider);
    setState(() {
      _error = null;
      _preparing = true;
    });
    try {
      final saved = refresh ? null : await repo.saved(userId, widget.lessonUid);
      if (saved != null) {
        _show(saved);
        // Online, the API confirms access is still active; a refusal deletes the copy (refund, takedown…).
        await repo.checkAccess(userId, widget.lessonUid);
        return;
      }
      _show(await repo.fetch(userId, widget.lessonUid, cancelled: () => _cancelled(userId)));
    } on DocumentCancelled {
      return;
    } on ApiException catch (e) {
      if (!mounted) return;
      final revoked = _pdf != null && !refresh;
      if (_pdf != null && refresh) {
        // The copy on screen stays; say why it couldn't be refreshed.
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(_errorText(AppLocalizations.of(context), e))));
        return;
      }
      setState(() {
        if (revoked) {
          _pdf?.dispose();
          _pdf = null;
        }
        _error = e;
      });
    } finally {
      if (mounted) setState(() => _preparing = false);
    }
  }

  void _show(File file) {
    if (!mounted) return;
    final old = _pdf;
    setState(() => _pdf = PdfControllerPinch(document: PdfDocument.openFile(file.path)));
    old?.dispose();
  }

  String _errorText(AppLocalizations l10n, ApiException e) => switch (e.code) {
    ApiException.networkCode => l10n.documentOffline,
    'document.still_processing' => l10n.documentStillProcessing,
    _ => apiErrorText(l10n, e),
  };

  Future<void> _markDone() async {
    final userId = _userId;
    if (userId == null) return;
    await ref.read(progressRepositoryProvider).record(userId: userId, lessonUid: widget.lessonUid, completed: true);
    ref.read(syncSchedulerProvider).nudge();
    if (mounted) setState(() => _done = true);
  }

  @override
  void dispose() {
    _pdf?.dispose();
    unawaited(SecureScreen.disable());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final pdf = _pdf;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (pdf != null)
            IconButton(
              tooltip: l10n.documentRefresh,
              icon: const Icon(Icons.refresh),
              onPressed: _preparing ? null : () => _open(refresh: true),
            ),
        ],
      ),
      bottomNavigationBar: pdf == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                child: _done
                    ? OutlinedButton.icon(
                        onPressed: null,
                        icon: const Icon(Icons.check),
                        label: Text(l10n.learnLessonDone),
                      )
                    : FilledButton.icon(
                        onPressed: _markDone,
                        icon: const Icon(Icons.check),
                        label: Text(l10n.documentMarkDone),
                      ),
              ),
            ),
      body: pdf != null
          ? PdfViewPinch(controller: pdf)
          : Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: _error != null
                    ? Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(_errorText(l10n, _error!), textAlign: TextAlign.center),
                          const SizedBox(height: 12),
                          OutlinedButton(onPressed: _open, child: Text(l10n.commonRetry)),
                        ],
                      )
                    : Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const CircularProgressIndicator(),
                          const SizedBox(height: 16),
                          Text(l10n.documentPreparing, textAlign: TextAlign.center),
                        ],
                      ),
              ),
            ),
    );
  }
}
