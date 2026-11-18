---
template: default
w4os: confirm
cache_enable: false
visible: false
form:
  name: w4os-resend
  classes: w4os-form
  fields:
    name:
      type: text
      label: w4os.avatar_name
      placeholder: 'First Last'
      validate: { required: true }
  buttons:
    - { type: submit, value: w4os.resend_button, classes: 'button' }
  process:
    - w4os: resend
---
