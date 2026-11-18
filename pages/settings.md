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
        # The brand of the site, in the active theme's own fields (its custom_logo, its custom_favicon or favicon): shown only when
        # the theme has them, their destination and the files they take the theme's (set when the form is made)
        logo:
          type: file
          label: w4os.admin_site_logo
          help: w4os.admin_site_logo_help
          multiple: false
          accept: [image/*]
        favicon:
          type: file
          label: w4os.admin_site_favicon
          help: w4os.admin_site_favicon_help
          multiple: false
          accept: [image/png, image/svg+xml, .ico]
  buttons:
    - { type: submit, value: w4os.save_button, classes: 'btn-primary' }
  process:
    # The files uploaded moved to their destination first, the theme's
    - upload: true
    - w4os: settings
---
