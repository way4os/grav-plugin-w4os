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
        label: w4os.email
        autocomplete: email
        validate: { required: true }
      about:
        type: textarea
        label: w4os.about
        rows: 5
      wantsList:
        type: checkboxes
        label: w4os.wants
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
        placeholder: w4os.wants
      skillsList:
        type: checkboxes
        label: w4os.skills
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
        placeholder: w4os.skills
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
    buttons:
      - { type: submit, value: w4os.save_button, classes: 'button primary' }
    process:
      - w4os: profile
  w4os-password:
    classes: w4os-form
    fields:
      current:
        type: password
        label: w4os.current_password
        autocomplete: current-password
        validate: { required: true }
      password:
        type: password
        label: w4os.new_password
        help: w4os.password_help
        autocomplete: new-password
        minlength: 6
        maxlength: 16
        validate: { required: true }
      password_confirm:
        type: password
        label: w4os.password_confirm
        autocomplete: new-password
        minlength: 6
        maxlength: 16
        validate: { required: true }
    buttons:
      - { type: submit, value: w4os.password_button, classes: 'button' }
    process:
      - w4os: password
---
