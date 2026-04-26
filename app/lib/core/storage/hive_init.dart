import 'package:hive_flutter/hive_flutter.dart';

const articlesCacheBox = 'articles_cache';
const offlineQueueBox  = 'offline_queue';
const settingsBox      = 'settings';

Future<void> initHiveBoxes() async {
  await Hive.openBox(articlesCacheBox);
  await Hive.openBox(offlineQueueBox);
  await Hive.openBox(settingsBox);
}
