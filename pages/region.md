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
    # The main action last, at the far right: the page of the item, then Save
    - { type: button, id: w4os-view-item, value: w4os.action_view_item, url: '', classes: 'button secondary' }
    - { type: submit, value: w4os.save_button, classes: 'button primary' }
  process:
    - w4os: item
---
