fastlane documentation
----

# Installation

Make sure you have the latest version of the Xcode command line tools installed:

```sh
xcode-select --install
```

For _fastlane_ installation instructions, see [Installing _fastlane_](https://docs.fastlane.tools/#installing-fastlane)

# Available Actions

### build_android

```sh
[bundle exec] fastlane build_android
```

Build a signed Android release AAB

### deploy_android

```sh
[bundle exec] fastlane deploy_android
```

Build and (optionally) upload Android to Google Play internal track

### build_ios

```sh
[bundle exec] fastlane build_ios
```

Build a signed iOS release IPA

### deploy_ios

```sh
[bundle exec] fastlane deploy_ios
```

Build and (optionally) upload iOS to TestFlight

### build

```sh
[bundle exec] fastlane build
```

Build one or more platforms (platforms:"ios,android")

### deploy

```sh
[bundle exec] fastlane deploy
```

Deploy one or more platforms (platforms:"ios,android" submit:true)

----

This README.md is auto-generated and will be re-generated every time [_fastlane_](https://fastlane.tools) is run.

More information about _fastlane_ can be found on [fastlane.tools](https://fastlane.tools).

The documentation of _fastlane_ can be found on [docs.fastlane.tools](https://docs.fastlane.tools).
