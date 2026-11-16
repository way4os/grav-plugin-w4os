---
template: w4os
w4os: edit
cache_enable: false
visible: false
form:
  name: w4os-edit
  classes: w4os-form
  fields:
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
      type: text
      label: w4os.languages
    firstLife:
      type: textarea
      label: w4os.first_life
      rows: 3
    profileUrl:
      type: url
      label: w4os.profile_url
  buttons:
    - { type: submit, value: w4os.save_button, classes: 'button primary' }
  process:
    - w4os: edit
---
