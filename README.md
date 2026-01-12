=== Weather Shortcode | Jordan Bailey ===

Wordpress Weather plugin to create a shortcode to show live weather data from a chosen city. [weather_widget city="London"].

== Installation ==
1. Download or clone this repository.
2. Upload the 'weather-shortcode' folder to: /wp-content/plugins/
3. Activate "Weather Shortcode" from the Plugins menu.

== Configuration ==
1. Go to: Settings > Weather Shortcode
2. Enter your OpenWeatherMap API key.
3. Save changes.

== Usage ==
Add the shortcode to any post or page: [weather_widget city="London"]

Example output:
15°C, Cloudy

== Debug Mode ==
While logged in as an admin, append the following to any URL to clear the cache: ?debug=1

Example:
https://example.com/?debug=1

This is intended for development/testing only.
