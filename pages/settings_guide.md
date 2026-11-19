---
template: w4os-admin
w4os: settings_guide
cache_enable: false
visible: false
# The settings of a service of the grid (the engine's settings `guide`): its fields are the engine's (x-form of the settings), set
# when the form is made
form:
  name: w4os-service
  classes: 'w4os-form w4os-service'
  fields:
    id: { type: hidden }
  buttons:
    - { type: submit, value: w4os.action_save, classes: 'btn-primary' }
  process:
    - w4os: service
---
