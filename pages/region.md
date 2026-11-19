---
template: w4os-admin
w4os: item
cache_enable: false
visible: false
# The item of the engine this page shows (?id=<UUID>): its type, the page of its list
w4os_item:
  type: regions
  list: regions
  # Its lists of its own as tabs, as w4os-wp shows them on its page: its parcels (the engine's list, kept to the region)
  lists:
    - { type: parcels, filter: region, label: admin_tab_parcels, search: admin_search_parcels, empty: admin_no_parcels, count: admin_parcels_count }
form:
  name: w4os-item
  classes: 'w4os-form w4os-form-item'
  # Its fields are the engine's (x-form), set when the form is
  fields: {}
  buttons:
    # Save, at the far right (the page of the item is the first tab of the card)
    - { type: submit, value: w4os.save_button, classes: 'btn-primary' }
  process:
    - w4os: item
---
