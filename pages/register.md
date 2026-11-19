---
template: default
w4os: register
cache_enable: false
visible: false
form:
  name: w4os-register
  classes: w4os-form
  fields:
    # Invisible to people: a bot that fills it is refused (the honeypot of the Form plugin)
    honeypot:
      type: honeypot
    first_name:
      type: text
      label: w4os.first_name
      help: w4os.first_name_help
      autocomplete: given-name
      # A letter, then letters and numbers, as the viewers take a name (made plain as typed: js/avatar-name.js)
      maxlength: 32
      validate: { required: true, pattern: '[A-Za-z][A-Za-z0-9]*' }
    last_name:
      type: text
      label: w4os.last_name
      help: w4os.last_name_help
      autocomplete: family-name
      # A letter, then letters and numbers, as the viewers take a name (made plain as typed: js/avatar-name.js)
      maxlength: 32
      validate: { required: true, pattern: '[A-Za-z][A-Za-z0-9]*' }
    email:
      type: email
      label: w4os.email
      autocomplete: email
      validate: { required: true }
    password:
      type: password
      label: w4os.password
      description: w4os.password_help
      autocomplete: new-password
      minlength: 6
      maxlength: 16
      validate: { required: true }
    password_confirm:
      type: password
      label: w4os.password_confirm
      autocomplete: new-password
      minlength: 6
      maxlength: 16
      validate: { required: true }
    model:
      type: w4os_models
      label: w4os.model
      data-options@: '\Grav\Plugin\W4osPlugin::modelOptions'
      validate: { type: radio }
    terms:
      type: w4os_terms
      validate: { type: bool }
  buttons:
    - { type: submit, value: w4os.register_button, classes: 'button primary' }
  process:
    - w4os: register
---
