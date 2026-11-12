import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'app_database.g.dart';

/// Writes waiting to reach the API (docs/architecture.md §6 "Offline-first"). One row per account, kind and
/// key: a newer write for the same lesson merges into the pending row instead of piling up.
@DataClassName('OutboxRow')
class Outbox extends Table {
  IntColumn get id => integer().autoIncrement()();

  /// The account that made the write; only that account's rows are sent.
  TextColumn get userId => text()();

  /// Which handler sends it, e.g. `progress`.
  TextColumn get kind => text()();

  /// What it is about (e.g. the lesson uid); unique with [userId] and [kind].
  TextColumn get dedupeKey => text()();

  /// JSON body, merged by the handler for the same key.
  TextColumn get payload => text()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();

  /// Bumped on every merge: a row sent while a newer write was merged in is neither removed nor delayed.
  IntColumn get revision => integer().withDefault(const Constant(0))();
  IntColumn get attempts => integer().withDefault(const Constant(0))();

  /// Not sent before this (exponential backoff after failures).
  DateTimeColumn get nextAttemptAt => dateTime()();
  TextColumn get lastError => text().nullable()();

  @override
  List<Set<Column>> get uniqueKeys => [
    {userId, kind, dedupeKey},
  ];
}

/// This phone's view of lesson progress, so the player resumes offline. Monotonic like the API: the
/// furthest position wins and completion never regresses (PRD-06 FR-05).
@DataClassName('LessonProgressRow')
class LessonProgress extends Table {
  TextColumn get userId => text()();
  TextColumn get lessonUid => text()();
  IntColumn get positionSeconds => integer().withDefault(const Constant(0))();
  BoolColumn get completed => boolean().withDefault(const Constant(false))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {userId, lessonUid};
}

/// Small sync bookkeeping (last successful sync, …).
@DataClassName('SyncStateRow')
class SyncState extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}

/// API answers kept for offline reading (catalogue, course and professor pages). Read first, refreshed from
/// the network; pruned by age and count (see `ResponseCache`).
@DataClassName('CachedResponseRow')
class CachedResponses extends Table {
  /// What was asked, e.g. `course:algebre-pour-l-exetat` or `courses?q=maths&page=1`.
  TextColumn get key => text()();

  /// The JSON body as the API sent it.
  TextColumn get body => text()();
  DateTimeColumn get fetchedAt => dateTime()();

  /// Last time a screen read it: what nobody reads goes first when pruning.
  DateTimeColumn get usedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {key};
}

/// The app's offline database (drift / SQLite): the source of truth for the UI; the network refreshes it.
@DriftDatabase(tables: [Outbox, LessonProgress, SyncState, CachedResponses])
class AppDatabase extends _$AppDatabase {
  AppDatabase([QueryExecutor? executor]) : super(executor ?? driftDatabase(name: 'yekkola'));

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (m) => m.createAll(),
    onUpgrade: (m, from, to) async {
      // v2: offline copies of catalogue answers.
      if (from < 2) await m.createTable(cachedResponses);
    },
  );
}
