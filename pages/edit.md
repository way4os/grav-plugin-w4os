---
template: w4os
w4os: edit
cache_enable: false
visible: false
form:
  name: w4os-edit
  classes: w4os-form
  fields:
    avatar:
      type: hidden
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
    about:
      type: textarea
      label: w4os.about
      rows: 5
    wants_group:
      type: fieldset
      legend: w4os.wants
      classes: w4os-interests
      fields:
        wantsList:
          type: checkboxes
          classes: w4os-flags
          options:
            build: w4os.want_build
            explore: w4os.want_explore
            meet: w4os.want_meet
            group: w4os.want_group
            buy: w4os.want_buy
            sell: w4os.want_sell
            be-hired: w4os.want_be_hired
            hire: w4os.want_hire
        wants:
          type: text
    skills_group:
      type: fieldset
      legend: w4os.skills
      classes: w4os-interests
      fields:
        skillsList:
          type: checkboxes
          classes: w4os-flags
          options:
            textures: w4os.skill_textures
            architecture: w4os.skill_architecture
            event-planning: w4os.skill_event_planning
            modeling: w4os.skill_modeling
            scripting: w4os.skill_scripting
            custom-characters: w4os.skill_custom_characters
        skills:
          type: text
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
