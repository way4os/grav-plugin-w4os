---
template: w4os
w4os: confirm
cache_enable: false
visible: false
form:
  name: w4os-resend
  classes: w4os-form
  fields:
    name:
      type: text
      label: PLUGIN_W4OS.AVATAR_NAME
      placeholder: 'First Last'
      validate: { required: true }
  buttons:
    - { type: submit, value: PLUGIN_W4OS.RESEND_BUTTON, classes: 'button' }
  process:
    - w4os: resend
---
