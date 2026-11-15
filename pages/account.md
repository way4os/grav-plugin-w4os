---
template: w4os
w4os: account
cache_enable: false
visible: false
forms:
  w4os-profile:
    classes: w4os-form
    fields:
      email:
        type: email
        label: PLUGIN_W4OS.EMAIL
        autocomplete: email
        validate: { required: true }
      about:
        type: textarea
        label: PLUGIN_W4OS.ABOUT
        rows: 5
      wants:
        type: text
        label: PLUGIN_W4OS.WANTS
      skills:
        type: text
        label: PLUGIN_W4OS.SKILLS
      languages:
        type: text
        label: PLUGIN_W4OS.LANGUAGES
      firstLife:
        type: textarea
        label: PLUGIN_W4OS.FIRST_LIFE
        rows: 3
      profileUrl:
        type: url
        label: PLUGIN_W4OS.PROFILE_URL
    buttons:
      - { type: submit, value: PLUGIN_W4OS.SAVE_BUTTON, classes: 'button primary' }
    process:
      - w4os: profile
  w4os-password:
    classes: w4os-form
    fields:
      current:
        type: password
        label: PLUGIN_W4OS.CURRENT_PASSWORD
        autocomplete: current-password
        validate: { required: true }
      password:
        type: password
        label: PLUGIN_W4OS.NEW_PASSWORD
        help: PLUGIN_W4OS.PASSWORD_HELP
        autocomplete: new-password
        minlength: 6
        maxlength: 16
        validate: { required: true }
      password_confirm:
        type: password
        label: PLUGIN_W4OS.PASSWORD_CONFIRM
        autocomplete: new-password
        minlength: 6
        maxlength: 16
        validate: { required: true }
    buttons:
      - { type: submit, value: PLUGIN_W4OS.PASSWORD_BUTTON, classes: 'button' }
    process:
      - w4os: password
---
