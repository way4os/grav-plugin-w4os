---
template: w4os
w4os: register
cache_enable: false
visible: false
form:
  name: w4os-register
  classes: w4os-form
  fields:
    first_name:
      type: text
      label: PLUGIN_W4OS.FIRST_NAME
      autocomplete: given-name
      maxlength: 63
      validate: { required: true }
    last_name:
      type: text
      label: PLUGIN_W4OS.LAST_NAME
      autocomplete: family-name
      maxlength: 63
      validate: { required: true }
    email:
      type: email
      label: PLUGIN_W4OS.EMAIL
      autocomplete: email
      validate: { required: true }
    password:
      type: password
      label: PLUGIN_W4OS.PASSWORD
      help: PLUGIN_W4OS.PASSWORD_HELP
      autocomplete: new-password
      maxlength: 16
      validate: { required: true }
    password_confirm:
      type: password
      label: PLUGIN_W4OS.PASSWORD_CONFIRM
      autocomplete: new-password
      maxlength: 16
      validate: { required: true }
    model:
      type: w4os_models
      label: PLUGIN_W4OS.MODEL
      data-options@: '\Grav\Plugin\W4osPlugin::modelOptions'
      validate: { type: radio }
    terms:
      type: w4os_terms
      validate: { type: bool }
  buttons:
    - { type: submit, value: PLUGIN_W4OS.REGISTER_BUTTON, classes: 'button primary' }
  process:
    - w4os: register
---
