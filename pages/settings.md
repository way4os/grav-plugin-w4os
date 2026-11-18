---
template: w4os-admin
w4os: settings
cache_enable: false
visible: false
# The settings of the site, for the managers the engine lets change them (x-site): each section a side tab of the page, its fields
# in it; the choices set when the form is made (the themes installed)
form:
  name: w4os-settings
  classes: 'w4os-form w4os-settings'
  fields:
    appearance:
      type: fieldset
      id: w4os-settings-appearance
      title: w4os.admin_site_appearance
      fields:
        theme:
          type: select
          label: w4os.admin_site_theme
          help: w4os.admin_site_theme_help
          # The same field as the other choices of the admin (Tom Select, js/select.js)
          datasets: { w4os-select: one }
          validate: { required: true }
  buttons:
    - { type: submit, value: w4os.save_button, classes: 'btn-primary' }
  process:
    - w4os: settings
---
