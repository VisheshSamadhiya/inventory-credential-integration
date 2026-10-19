<?php if ($canViewSoftware && ($canAddSoftware || $canEditSoftware || $canManageEmployeeCredentials)): ?>
<dialog class="employee-dialog software-dialog" id="software-add" aria-labelledby="software-dialog-heading">
    <header class="software-dialog-header"><h2 id="software-dialog-heading">Add Software</h2></header>
    <form method="post" class="profile-form employee-software-form" id="software-form">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        <input type="hidden" name="employee_id" value="<?=$employeeId?>">
        <input type="hidden" name="action" id="software-action" value="software_add">
        <input type="hidden" name="software_id" id="software-id">
        <input type="hidden" name="credential_action" id="credential-action" value="">
        <input type="hidden" name="credential_id" id="credential-id" value="">

        <div class="software-dialog-body">
            <section class="software-details-section" aria-labelledby="software-details-title">
                <h3 id="software-details-title">Software Details</h3>
                <div class="software-details-grid">
                    <div><label for="software-name">Software Name</label><input id="software-name" name="software_name" maxlength="180" required <?=$canEditSoftware?'':'disabled'?>></div>
                    <div><label for="software-version">Version</label><input id="software-version" name="version" maxlength="100" <?=$canEditSoftware?'':'disabled'?>></div>
                    <div><label for="software-license">License Type</label><input id="software-license" name="license_type" maxlength="100" <?=$canEditSoftware?'':'disabled'?>></div>
                    <div><label for="software-date">Installation Date</label><input id="software-date" name="installation_date" type="date" <?=$canEditSoftware?'':'disabled'?>></div>
                    <div><label for="software-status">Status</label><input id="software-status" value="Installed" readonly aria-readonly="true"></div>
                    <div class="software-details-notes"><label for="software-notes">Notes</label><textarea id="software-notes" name="software_notes" <?=$canEditSoftware?'':'disabled'?>></textarea></div>
                </div>
            </section>

            <?php if ($canManageEmployeeCredentials): ?>
            <details class="employee-software-credentials" id="software-credential-section">
                <summary>Associated Credentials (Optional)</summary>
                <div class="software-credential-content">
                    <?php if (!$canViewCredentials): ?>
                        <p class="muted">Credential details require the Credentials view permission.</p>
                    <?php else: ?>
                        <div class="table-scroll" id="software-credential-list"></div>
                    <?php endif; ?>

                    <?php if (($canEditCredentials && $canViewCredentials) || $canCreateEmployeeCredentials): ?>
                    <fieldset class="credential-choice" id="credential-choice">
                        <legend>Choose an action</legend>
                            <?php if ($canEditCredentials && $canViewCredentials): ?><label class="credential-choice-option"><input type="radio" name="credential_mode" value="link" data-credential-mode> Link Existing Credential</label><?php endif; ?>
                        <?php if ($canCreateEmployeeCredentials): ?><label class="credential-choice-option"><input type="radio" name="credential_mode" value="create" data-credential-mode> Create New Credential</label><?php endif; ?>
                    </fieldset>
                    <?php endif; ?>

                    <?php if ($canEditCredentials && $canViewCredentials): ?>
                    <div class="credential-action-fields" id="credential-link-fields" hidden>
                        <label for="link-credential-id">Link Existing Credential</label>
                        <select id="link-credential-id" name="link_credential_id" disabled>
                            <option value="">Select an unlinked employee credential</option>
                            <?php foreach ($employeeCredentials as $credential): ?><?php if (!(int)($credential['software_installation_id'] ?? 0)): ?>
                            <option value="<?=$credential['id']?>"><?=htmlspecialchars($credential['service_name'].' · '.($credential['credential_username']?:'no username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></option>
                            <?php endif; ?><?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($canCreateEmployeeCredentials): ?>
                    <div class="credential-action-fields" id="credential-create-fields" hidden>
                        <div class="credential-input-row">
                            <div><label for="new-credential-service">Service Name</label><input id="new-credential-service" name="new_credentials[0][service_name]" maxlength="180" disabled></div>
                            <div><label for="new-credential-username">Username / Email</label><input id="new-credential-username" name="new_credentials[0][credential_username]" maxlength="190" autocomplete="off" disabled></div>
                            <div><label for="new-credential-password">Password</label><input id="new-credential-password" name="new_credentials[0][credential_password]" type="password" maxlength="4096" autocomplete="new-password" disabled></div>
                            <div><label for="new-credential-url">Service URL</label><input id="new-credential-url" name="new_credentials[0][service_url]" type="url" maxlength="500" placeholder="https://" disabled></div>
                            <div class="form-wide"><label for="new-credential-notes">Credential Notes</label><textarea id="new-credential-notes" name="new_credentials[0][notes]" disabled></textarea></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewCredentials): ?><div class="credential-edit-fields" id="credential-edit-fields" hidden>
                        <h4>Edit Credential</h4>
                        <div class="credential-input-row">
                            <div><label for="edit-service-name">Service Name</label><input id="edit-service-name" name="service_name" maxlength="180" disabled></div>
                            <div><label for="edit-username">Username / Email</label><input id="edit-username" name="credential_username" maxlength="190" autocomplete="off" disabled></div>
                            <div><label for="edit-password">New Password</label><input id="edit-password" name="credential_password" type="password" maxlength="4096" autocomplete="new-password" disabled><small class="muted">Leave blank to keep the current encrypted password.</small></div>
                            <div><label for="edit-service-url">Service URL</label><input id="edit-service-url" name="service_url" type="url" maxlength="500" placeholder="https://" disabled></div>
                            <div class="form-wide"><label for="edit-credential-notes">Credential Notes</label><textarea id="edit-credential-notes" name="credential_notes" disabled></textarea></div>
                        </div>
                    </div><?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <footer class="software-dialog-footer" id="software-save-actions">
            <button class="btn btn-glow" type="submit" id="save-software-button">Save Software</button>
            <button class="btn" type="button" data-close-dialog="software-add">Cancel</button>
        </footer>
    </form>
</dialog>
<div id="employee-software-credential-data" hidden data-items="<?=htmlspecialchars(json_encode(array_map(static fn(array $credential): array => ['id'=>(int)$credential['id'],'service_name'=>$credential['service_name'],'service_url'=>$credential['service_url'],'credential_username'=>$credential['credential_username'],'software_installation_id'=>$credential['software_installation_id']===null?null:(int)$credential['software_installation_id'],'notes'=>$credential['notes']],$employeeCredentials), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-view="<?=$canViewCredentials?'1':'0'?>" data-edit="<?=$canEditCredentials && $canViewCredentials?'1':'0'?>" data-create="<?=$canCreateEmployeeCredentials?'1':'0'?>" data-remove="<?=$canDeleteCredentials?'1':'0'?>" data-reveal="<?=$canRevealCredentials?'1':'0'?>" data-software-edit="<?=$canEditSoftware?'1':'0'?>"></div>
<script>
(function(){
 const dialog=document.getElementById('software-add'),form=document.getElementById('software-form');
 if(!dialog||!form)return;
 const softwareAction=document.getElementById('software-action'),softwareId=document.getElementById('software-id'),credentialAction=document.getElementById('credential-action'),credentialId=document.getElementById('credential-id');
 const credentialData=document.getElementById('employee-software-credential-data');
 const credentials=JSON.parse(credentialData?credentialData.dataset.items||'[]':'[]');
 const flags={view:!!(credentialData&&credentialData.dataset.view==='1'),edit:!!(credentialData&&credentialData.dataset.edit==='1'),create:!!(credentialData&&credentialData.dataset.create==='1'),remove:!!(credentialData&&credentialData.dataset.remove==='1'),reveal:!!(credentialData&&credentialData.dataset.reveal==='1'),softwareEdit:!!(credentialData&&credentialData.dataset.softwareEdit==='1')};
 const name=document.getElementById('software-name'),version=document.getElementById('software-version'),license=document.getElementById('software-license'),date=document.getElementById('software-date'),status=document.getElementById('software-status'),notes=document.getElementById('software-notes'),credentialList=document.getElementById('software-credential-list'),linkFields=document.getElementById('credential-link-fields'),createFields=document.getElementById('credential-create-fields'),editor=document.getElementById('credential-edit-fields'),saveButton=document.getElementById('save-software-button');
 function element(tag,cls,text){const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;}
 function setFieldGroup(group,active){if(!group)return;group.hidden=!active;group.querySelectorAll('input,select,textarea').forEach(field=>field.disabled=!active);}
 function syncCredentialMode(){const selected=form.querySelector('input[name="credential_mode"]:checked');const mode=selected?selected.value:'';setFieldGroup(linkFields,mode==='link');setFieldGroup(createFields,mode==='create');}
 function act(action,id,confirmText){if(confirmText&&!window.confirm(confirmText))return;credentialAction.value=action;credentialId.value=String(id);form.requestSubmit(saveButton);}
 function hideEditor(){if(!editor)return;editor.hidden=true;editor.querySelectorAll('input,textarea').forEach(field=>{field.value='';field.disabled=true;});}
 function renderCredentials(currentSoftware){
  if(credentialList){credentialList.replaceChildren();const linked=credentials.filter(item=>item.software_installation_id===Number(currentSoftware.id));
   if(!linked.length){credentialList.append(element('p','empty-state','No linked credentials.'));}
   else{const table=element('table'),head=element('thead'),headRow=element('tr');['Service','Username / Email','Service URL','Notes','Actions'].forEach(label=>headRow.append(element('th','',label)));head.append(headRow);const body=element('tbody');
    linked.forEach(item=>{const row=element('tr');row.append(element('td','',item.service_name),element('td','',item.credential_username||'—'),element('td','',item.service_url||'—'),element('td','',item.notes||'—'));const actions=element('td','credential-actions');
     if(flags.view){const view=element('a','btn','View');view.href='credentials.php?view='+encodeURIComponent(item.id);actions.append(view);}
     if(flags.reveal){const reveal=element('button','btn','Reveal');reveal.type='button';reveal.dataset.revealCredential=String(item.id);actions.append(reveal);}
     if(flags.edit){const edit=element('button','btn','Edit');edit.type='button';edit.addEventListener('click',()=>{if(!editor)return;form.querySelectorAll('input[name="credential_mode"]').forEach(radio=>radio.checked=false);syncCredentialMode();credentialId.value=String(item.id);[['edit-service-name',item.service_name],['edit-username',item.credential_username],['edit-service-url',item.service_url],['edit-credential-notes',item.notes]].forEach(([id,value])=>{const field=document.getElementById(id);field.value=value||'';field.disabled=false;});document.getElementById('edit-password').value='';document.getElementById('edit-password').disabled=false;editor.hidden=false;editor.scrollIntoView({block:'nearest'});});actions.append(edit);
      const unlink=element('button','btn','Unlink');unlink.type='button';unlink.addEventListener('click',()=>act('credential_unlink',item.id,'Unlink this credential from the software? It will remain linked to this employee.'));actions.append(unlink);}
     if(flags.remove){const remove=element('button','btn btn-danger','Delete');remove.type='button';remove.addEventListener('click',()=>act('credential_delete',item.id,'Remove this credential from the active list? Its history will be preserved.'));actions.append(remove);}
     row.append(actions);body.append(row);
    });table.append(head,body);const scroll=element('div','table-scroll');scroll.append(table);credentialList.append(scroll);}
   const select=document.getElementById('link-credential-id');if(select){Array.from(select.options).forEach(option=>{if(!option.value)return;const linkedTo=credentials.find(item=>item.id===Number(option.value));option.hidden=!!(linkedTo&&linkedTo.software_installation_id);});select.value='';}
  }
  hideEditor();syncCredentialMode();
 }
 function openSoftware(software,isNew){
  form.reset();credentialAction.value='';credentialId.value='';softwareId.value=isNew?'':software.id;softwareAction.value=isNew?'software_add':'software_edit';
  document.getElementById('software-dialog-heading').textContent=isNew?'Add Software':(flags.softwareEdit?'Manage Software':'Software Details');
  name.value=isNew?'':software.software_name||'';version.value=software.version||'';license.value=software.license_type||'';date.value=software.installation_date||'';status.value=isNew?'Installed':(software.status==='installed'?'Installed':'Uninstalled');notes.value=software.notes||'';
  ['software-name','software-version','software-license','software-date','software-notes'].forEach(id=>{document.getElementById(id).disabled=!isNew&&!flags.softwareEdit;});
  saveButton.hidden=!isNew&&!flags.softwareEdit&&!flags.edit&&!flags.create;
  const section=document.getElementById('software-credential-section');if(section)section.open=false;
  renderCredentials(isNew?{id:0,isNew:true}:software);
  dialog.showModal();
 }
 document.querySelectorAll('[data-open-software]').forEach(button=>button.addEventListener('click',()=>openSoftware(JSON.parse(button.dataset.openSoftware),false)));
 const addSoftware=document.getElementById('open-add-software');if(addSoftware)addSoftware.addEventListener('click',()=>openSoftware({},true));
 document.querySelectorAll('[data-close-dialog="software-add"]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
 dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
 dialog.addEventListener('close',()=>{form.reset();credentialAction.value='';credentialId.value='';softwareId.value='';hideEditor();setFieldGroup(linkFields,false);setFieldGroup(createFields,false);});
 form.querySelectorAll('input[name="credential_mode"]').forEach(radio=>radio.addEventListener('change',syncCredentialMode));
 form.addEventListener('submit',event=>{if(credentialAction.value)return;const mode=form.querySelector('input[name="credential_mode"]:checked');if(editor&&!editor.hidden){credentialAction.value='credential_update';return;}if(mode&&mode.value==='link'&&!document.getElementById('link-credential-id')?.value){event.preventDefault();window.alert('Select an existing credential to link.');return;}if(softwareAction.value==='software_edit'&&!flags.softwareEdit){if(mode&&mode.value==='link'){credentialAction.value='credential_link';credentialId.value=document.getElementById('link-credential-id').value;}else if(mode&&mode.value==='create'){credentialAction.value='credential_create';}}});
})();
</script>
<?php endif; ?>
