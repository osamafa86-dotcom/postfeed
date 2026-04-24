Place a 1024x1024 px PNG here named `AppIcon-1024.png`.
You can export it from the brand teal (#1a5c5c) + white "نيوز فيد" wordmark
using the existing /icon.php endpoint on the backend:

   curl -o AppIcon-1024.png "https://feedsnews.net/icon.php?size=1024"

Apple requires a single 1024x1024 icon for iOS 14+ and it auto-generates
the smaller sizes at build time.
