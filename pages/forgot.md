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
      label: PLUGIN_W4OS.LOGIN
      autocomplete: username
      validate: { required: true }
  buttons:
    - { type: submit, value: PLUGIN_W4OS.FORGOT_BUTTON, classes: 'button primary' }
  process:
    - w4os: forgot
---
