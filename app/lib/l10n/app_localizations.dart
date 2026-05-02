import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_ar.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
      : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations? of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations);
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
    delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
  ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('ar'),
    Locale('en')
  ];

  /// No description provided for @appName.
  ///
  /// In ar, this message translates to:
  /// **'فيد نيوز'**
  String get appName;

  /// No description provided for @tagline.
  ///
  /// In ar, this message translates to:
  /// **'مجمع المصادر الإخبارية'**
  String get tagline;

  /// No description provided for @tabHome.
  ///
  /// In ar, this message translates to:
  /// **'الرئيسية'**
  String get tabHome;

  /// No description provided for @tabDiscover.
  ///
  /// In ar, this message translates to:
  /// **'استكشف'**
  String get tabDiscover;

  /// No description provided for @tabPodcast.
  ///
  /// In ar, this message translates to:
  /// **'البودكاست'**
  String get tabPodcast;

  /// No description provided for @tabFollow.
  ///
  /// In ar, this message translates to:
  /// **'متابعتي'**
  String get tabFollow;

  /// No description provided for @tabProfile.
  ///
  /// In ar, this message translates to:
  /// **'حسابي'**
  String get tabProfile;

  /// No description provided for @breaking.
  ///
  /// In ar, this message translates to:
  /// **'عاجل'**
  String get breaking;

  /// No description provided for @trending.
  ///
  /// In ar, this message translates to:
  /// **'الأكثر تداولاً'**
  String get trending;

  /// No description provided for @search.
  ///
  /// In ar, this message translates to:
  /// **'ابحث في الأخبار'**
  String get search;

  /// No description provided for @categories.
  ///
  /// In ar, this message translates to:
  /// **'الأقسام'**
  String get categories;

  /// No description provided for @sources.
  ///
  /// In ar, this message translates to:
  /// **'المصادر'**
  String get sources;

  /// No description provided for @latestNews.
  ///
  /// In ar, this message translates to:
  /// **'آخر الأخبار'**
  String get latestNews;

  /// No description provided for @relatedArticles.
  ///
  /// In ar, this message translates to:
  /// **'مقالات ذات صلة'**
  String get relatedArticles;

  /// No description provided for @readSource.
  ///
  /// In ar, this message translates to:
  /// **'قراءة المصدر الأصلي'**
  String get readSource;

  /// No description provided for @shareArticle.
  ///
  /// In ar, this message translates to:
  /// **'مشاركة المقال'**
  String get shareArticle;

  /// No description provided for @saveArticle.
  ///
  /// In ar, this message translates to:
  /// **'حفظ المقال'**
  String get saveArticle;

  /// No description provided for @stories.
  ///
  /// In ar, this message translates to:
  /// **'القصص المتطورة'**
  String get stories;

  /// No description provided for @timelines.
  ///
  /// In ar, this message translates to:
  /// **'الجداول الزمنية'**
  String get timelines;

  /// No description provided for @newsMap.
  ///
  /// In ar, this message translates to:
  /// **'خريطة الأخبار'**
  String get newsMap;

  /// No description provided for @sabah.
  ///
  /// In ar, this message translates to:
  /// **'الموجز الصباحي'**
  String get sabah;

  /// No description provided for @weekly.
  ///
  /// In ar, this message translates to:
  /// **'مراجعة الأسبوع'**
  String get weekly;

  /// No description provided for @askAi.
  ///
  /// In ar, this message translates to:
  /// **'اسأل الذكاء'**
  String get askAi;

  /// No description provided for @podcast.
  ///
  /// In ar, this message translates to:
  /// **'البودكاست'**
  String get podcast;

  /// No description provided for @gallery.
  ///
  /// In ar, this message translates to:
  /// **'معرض الصور'**
  String get gallery;

  /// No description provided for @reels.
  ///
  /// In ar, this message translates to:
  /// **'ريلز'**
  String get reels;

  /// No description provided for @telegram.
  ///
  /// In ar, this message translates to:
  /// **'تيليجرام'**
  String get telegram;

  /// No description provided for @twitter.
  ///
  /// In ar, this message translates to:
  /// **'تويتر'**
  String get twitter;

  /// No description provided for @youtube.
  ///
  /// In ar, this message translates to:
  /// **'يوتيوب'**
  String get youtube;

  /// No description provided for @login.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل الدخول'**
  String get login;

  /// No description provided for @register.
  ///
  /// In ar, this message translates to:
  /// **'إنشاء حساب'**
  String get register;

  /// No description provided for @logout.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل الخروج'**
  String get logout;

  /// No description provided for @settings.
  ///
  /// In ar, this message translates to:
  /// **'الإعدادات'**
  String get settings;

  /// No description provided for @darkMode.
  ///
  /// In ar, this message translates to:
  /// **'الوضع الداكن'**
  String get darkMode;

  /// No description provided for @lightMode.
  ///
  /// In ar, this message translates to:
  /// **'الوضع الفاتح'**
  String get lightMode;

  /// No description provided for @autoMode.
  ///
  /// In ar, this message translates to:
  /// **'تلقائي'**
  String get autoMode;

  /// No description provided for @privacyPolicy.
  ///
  /// In ar, this message translates to:
  /// **'سياسة الخصوصية'**
  String get privacyPolicy;

  /// No description provided for @terms.
  ///
  /// In ar, this message translates to:
  /// **'شروط الاستخدام'**
  String get terms;

  /// No description provided for @contact.
  ///
  /// In ar, this message translates to:
  /// **'تواصل معنا'**
  String get contact;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['ar', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'ar':
      return AppLocalizationsAr();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
      'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
      'an issue with the localizations generation tool. Please file an issue '
      'on GitHub with a reproducible sample app and the gen-l10n configuration '
      'that was used.');
}
