<!-- Part of the Monocle documentation - see README.md -->

# Validation

Part of [what gets recorded](../lenses.md).

![The Validation tab](../images/50-lens-validation-light.webp#gh-light-mode-only)
![The Validation tab](../images/50-lens-validation-dark.webp#gh-dark-mode-only)

Section `validation`: every validation - form requests, `$request->validate()`, Livewire and your own `Validator::make()` - with where it came from (the form request or Livewire component), the rules per field and, when it failed, each failed rule with its parameters and message. The input of the validated fields is kept too, with secrets masked like request input, uploaded files described by name, size and type, and long values cut short. Rule objects and closures are recorded as the class you wrote (with its public properties) or the file and line of the closure.

A resolver you set with `Validator::resolver()` keeps working.
