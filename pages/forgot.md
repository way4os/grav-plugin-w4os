---
template: w4os
w4os: forgot
cache_enable: false
visible: false
form:
  name: w4os-forgot
  classes: w4os-form
  fields:
    login:
      type: text
      label: w4os.login
      autocomplete: username
      validate: { required: true }
  buttons:
    - { type: submit, value: w4os.forgot_button, classes: 'button primary' }
  process:
    - w4os: forgot
---
