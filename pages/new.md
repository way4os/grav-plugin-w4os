---
template: w4os-admin
w4os: new
cache_enable: false
visible: false
# A new avatar, made by a manager: its fields are the engine's (x-form of its creation), set when the form is made
form:
  name: w4os-new
  classes: 'w4os-form w4os-new-avatar'
  fields:
    id: { type: hidden }
  buttons:
    - { type: submit, value: w4os.action_create_avatar, classes: 'btn-primary' }
  process:
    - w4os: create
---
