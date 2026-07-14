# fastlane-plugin-app_manager

A starter plugin for connecting Fastlane delivery lanes to App Manager.

```ruby
lane :release do
  app_manager(message: "release started")
end
```

Run tests with `bundle exec rspec` and build the gem with `gem build fastlane-plugin-app_manager.gemspec`.

