---
template: w4os
w4os: account
cache_enable: false
visible: false
forms:
  w4os-profile:
    classes: 'w4os-form w4os-form-profile'
    fields:
      # The sections of a profile, in the order of the viewer's tabs
      profile:
        type: tabs
        fields:
          inworld:
            type: tab
            title: w4os.profile_tab_inworld
            fields:
              identity:
                type: w4os_identity
              about:
                type: textarea
                classes: w4os-autogrow
                label: w4os.about
                rows: 5
              groups:
                type: w4os_groups
              published:
                type: checkbox
                label: w4os.show_in_search
          web:
            type: tab
            title: w4os.profile_tab_web
            fields:
              profileUrl:
                type: url
                label: w4os.profile_url
          interests:
            type: tab
            title: w4os.profile_tab_interests
            fields:
              interests_note:
                type: w4os_note
                text: w4os.interests_removed
              wants_group:
                type: fieldset
                legend: w4os.wants
                classes: w4os-interests
                fields:
                  wantsList:
                    type: checkboxes
                    label: false
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
                    label: false
                    title: w4os.wants
              skills_group:
                type: fieldset
                legend: w4os.skills
                classes: w4os-interests
                fields:
                  skillsList:
                    type: checkboxes
                    label: false
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
                    label: false
                    title: w4os.skills
              languages:
                type: w4os_languages
                label: w4os.languages
          picks:
            type: tab
            title: w4os.profile_tab_picks
            fields:
              picks_list:
                type: w4os_items
                kind: picks
          classifieds:
            type: tab
            title: w4os.profile_tab_classifieds
            fields:
              classifieds_list:
                type: w4os_items
                kind: classifieds
          real_life:
            type: tab
            title: w4os.profile_tab_real_life
            fields:
              first_picture:
                type: w4os_photo
                picture: firstImage
              firstLife:
                type: textarea
                classes: w4os-autogrow
                label: w4os.real_life
                rows: 3
          account:
            type: tab
            title: w4os.profile_tab_account
            fields:
              email:
                type: email
                label: w4os.email
                autocomplete: email
                validate: { required: true }
              # A new password, given with the current one (both optional: the rest of the profile saves without them)
              current:
                type: password
                label: w4os.current_password
                autocomplete: current-password
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
      # The main action last, at the far right: the profile page of the avatar (its address set when the form is), then Save
      - { type: button, id: w4os-view-profile, value: w4os.action_view, url: '', classes: 'button secondary' }
      - { type: submit, value: w4os.save_button, classes: 'button primary' }
    process:
      - w4os: profile
---
