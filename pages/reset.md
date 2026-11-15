---
template: w4os
w4os: reset
cache_enable: false
visible: false
form:
  name: w4os-reset
  classes: w4os-form
  fields:
    token:
      type: hidden
      evaluate: true
      default: "uri.query('token')"
    password:
      type: password
      label: PLUGIN_W4OS.NEW_PASSWORD
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
  buttons:
    - { type: submit, value: PLUGIN_W4OS.RESET_BUTTON, classes: 'button primary' }
  process:
    - w4os: reset
---
