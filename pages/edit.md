---
template: w4os
w4os: edit
cache_enable: false
visible: false
form:
  name: w4os-edit
  classes: w4os-form
  fields:
    role:
      type: select
      label: w4os.role
      options:
        resident: w4os.role_resident
        god-like: w4os.role_god_like
        customer-service: w4os.role_customer_service
        liaison: w4os.role_liaison
        god: w4os.role_god
    email:
      type: email
      label: w4os.email
      validate: { required: true }
    about:
      type: textarea
      label: w4os.about
      rows: 5
    wants:
      type: text
      label: w4os.wants
    skills:
      type: text
      label: w4os.skills
    languages:
      type: w4os_languages
      label: w4os.languages
    firstLife:
      type: textarea
      label: w4os.first_life
      rows: 3
    profileUrl:
      type: url
      label: w4os.profile_url
    # A new password, set by the manager (empty: unchanged)
    password:
      type: password
      label: w4os.new_password
      help: w4os.password_help
      autocomplete: new-password
      minlength: 6
      maxlength: 16
    password_confirm:
      type: password
      label: w4os.password_confirm
      autocomplete: new-password
      minlength: 6
      maxlength: 16
  buttons:
    - { type: submit, value: w4os.save_button, classes: 'button primary' }
  process:
    - w4os: edit
---
