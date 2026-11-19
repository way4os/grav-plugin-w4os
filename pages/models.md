---
template: w4os-admin
w4os: models
cache_enable: false
visible: false
# The rule of the avatar models of the grid (its settings `models`): its fields are the engine's (x-form of the settings), set when
# the form is made
form:
  name: w4os-models
  classes: 'w4os-form w4os-models-rule'
  fields:
    id: { type: hidden }
  buttons:
    - { type: submit, value: w4os.action_save, classes: 'btn-primary' }
  process:
    - w4os: models
---
