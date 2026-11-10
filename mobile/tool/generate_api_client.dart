// Generates the typed API client in lib/core/api/generated from ../api/openapi.json.
//
//   dart run tool/generate_api_client.dart           regenerate
//   dart run tool/generate_api_client.dart --check   fail (exit 1) if the committed client is stale
//
// The app is a student client: admin, professor, staff and webhook operations are cut from the
// spec first, along with every schema only they use, so back-office-only shapes never reach Dart.
import 'dart:convert';
import 'dart:io';

const _excludedPrefixes = [
  '/admin',
  '/professor',
  '/_fake',
  '/webhooks',
  '/auth/staff',
  '/me/password',
  '/me/impersonation',
];

const _filteredSpecPath = '.dart_tool/openapi.mobile.json';

Future<void> main(List<String> args) async {
  final check = args.contains('--check');
  final source = File('../api/openapi.json');
  if (!source.existsSync()) {
    stderr.writeln('Missing ${source.path}. Run `composer openapi` in api/ first.');
    exit(2);
  }

  final spec = jsonDecode(source.readAsStringSync()) as Map<String, dynamic>;
  final filtered = filterSpec(spec);
  File(_filteredSpecPath)
    ..createSync(recursive: true)
    ..writeAsStringSync(const JsonEncoder.withIndent('  ').convert(filtered));

  final result = await Process.run(Platform.resolvedExecutable, [
    'run',
    'swagger_parser',
    if (check) '--check',
  ], runInShell: true);
  stdout.write(result.stdout);
  stderr.write(result.stderr);
  if (result.exitCode != 0 && check) {
    stderr.writeln('The generated API client is stale: run `dart run tool/generate_api_client.dart`.');
  }
  exit(result.exitCode);
}

/// Keeps the student-facing paths and only the component schemas/responses they reach.
Map<String, dynamic> filterSpec(Map<String, dynamic> spec) {
  final paths = (spec['paths'] as Map<String, dynamic>)
    ..removeWhere((path, _) => _excludedPrefixes.any((p) => path == p || path.startsWith('$p/')));

  // A query parameter is either sent or not: `type: [T, null]` (which swagger_parser rejects)
  // means the same as an optional T.
  for (final operation in paths.values.expand((p) => (p as Map).values).whereType<Map<Object?, Object?>>()) {
    for (final param in (operation['parameters'] as List? ?? const []).whereType<Map<Object?, Object?>>()) {
      var schema = param['schema'];
      if (param['in'] != 'query' || schema is! Map) continue;
      final anyOf = schema['anyOf'];
      if (anyOf is List) {
        final rest = anyOf.where((s) => !(s is Map && s['type'] == 'null')).toList();
        if (rest.length == 1) param['schema'] = schema = rest.single as Map;
      }
      final type = schema['type'];
      if (type is List && type.contains('null')) {
        final rest = type.where((t) => t != 'null').toList();
        schema['type'] = rest.length == 1 ? rest.single : rest;
      }
      final values = schema['enum'];
      if (values is List) values.remove(null);
    }
  }

  final components = spec['components'] as Map<String, dynamic>? ?? {};
  final reached = <String>{};
  final queue = <Object?>[paths];

  while (queue.isNotEmpty) {
    final node = queue.removeLast();
    if (node is Map) {
      final ref = node[r'$ref'];
      if (ref is String && ref.startsWith('#/components/') && reached.add(ref)) {
        final parts = ref.substring('#/components/'.length).split('/');
        queue.add((components[parts[0]] as Map?)?[parts[1]]);
      }
      queue.addAll(node.values);
    } else if (node is List) {
      queue.addAll(node);
    }
  }

  for (final entry in components.entries) {
    if (entry.value is Map) {
      (entry.value as Map).removeWhere((name, _) => !reached.contains('#/components/${entry.key}/$name'));
    }
  }

  return spec;
}
