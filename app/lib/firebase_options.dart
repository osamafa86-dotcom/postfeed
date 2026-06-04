import 'package:firebase_core/firebase_core.dart';

/// Firebase configuration for the iOS build, passed explicitly to
/// [Firebase.initializeApp] so the iOS app does NOT depend on a
/// GoogleService-Info.plist being added to the Xcode "Copy Bundle
/// Resources" build phase — an easy step to miss that makes push fail
/// silently (init throws, the catch swallows it, no FCM token is ever
/// obtained).
///
/// These values are NOT secret: the identical set ships inside every
/// copy of the app binary regardless. Source: Firebase console →
/// project "feedsnews" → iOS app "net.feedsnews.newsfeed".
const FirebaseOptions kFeedsNewsIosFirebaseOptions = FirebaseOptions(
  apiKey: 'AIzaSyDWEelwiLA41zYjWPuguGx7LG26AyriSrA',
  appId: '1:164298901015:ios:f1155d199b1caacc85be52',
  messagingSenderId: '164298901015',
  projectId: 'feedsnews',
  storageBucket: 'feedsnews.firebasestorage.app',
  iosBundleId: 'net.feedsnews.newsfeed',
);
