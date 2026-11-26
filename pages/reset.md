---
template: default
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
      label: w4os.new_password
      description: w4os.password_help
      autocomplete: new-password
      validate: { required: true }
    password_confirm:
      type: password
      label: w4os.password_confirm
      autocomplete: new-password
      validate: { required: true }
  buttons:
    - { type: submit, value: w4os.reset_button, classes: 'button primary' }
  process:
    - w4os: reset
---
