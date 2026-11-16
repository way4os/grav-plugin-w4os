---
template: w4os
w4os: item
cache_enable: false
visible: false
# The item of the engine this page shows (?id=<UUID>): its type, the page of its list
w4os_item:
  type: regions
  list: regions
form:
  name: w4os-item
  classes: 'w4os-form w4os-form-item'
  # Its fields are the engine's (x-form), set when the form is
  fields: {}
  buttons:
    - { type: submit, value: w4os.save_button, classes: 'button primary' }
  process:
    - w4os: item
---
