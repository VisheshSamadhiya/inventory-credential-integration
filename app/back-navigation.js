(function(){
 const backLink=document.querySelector('[data-app-back]');
 if(!backLink)return;
 const previousKey='im-app-previous-page';
 const appRoutes=new Set(['dashboard.php','products.php','inventory.php','assignments.php','employees.php','employee.php','categories.php','orders.php','vendors.php','vendor.php','history.php','users.php','audit_logs.php','credentials.php','credential-access.php','search.php']);
 const basePath=window.location.pathname.slice(0,window.location.pathname.lastIndexOf('/')+1);
 let internalDeparture=false;

 function safeAppUrl(value){
  try{
   const url=new URL(value,window.location.href);
   if(url.origin!==window.location.origin||!url.pathname.startsWith(basePath)||!appRoutes.has(url.pathname.slice(basePath.length)))return null;
   return url;
  }catch(_){return null}
 }
 function rememberCurrent(){
  const current=safeAppUrl(window.location.href);
  if(!current)return;
  try{sessionStorage.setItem(previousKey,current.href);internalDeparture=true}catch(_){}
 }
 function clearPrevious(){try{sessionStorage.removeItem(previousKey)}catch(_){}}

 document.addEventListener('click',function(event){
  if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
  const link=event.target.closest&&event.target.closest('a[href]');
  if(!link||link.closest('[data-app-back]')||link.hasAttribute('download')||link.relList.contains('external')||(link.target&&link.target.toLowerCase()!=='_self'))return;
  const raw=link.getAttribute('href');
  if(!raw||raw.includes('#'))return;
  const destination=safeAppUrl(link.href),current=safeAppUrl(window.location.href);
  if(!destination||!current||destination.href===current.href)return;
  rememberCurrent();
 },true);

 document.addEventListener('submit',function(event){
  if(event.defaultPrevented)return;
  const form=event.target;
  if(!(form instanceof HTMLFormElement))return;
  const submitter=event.submitter;
  const method=(submitter&&submitter.formMethod||form.method||'get').toLowerCase();
  const target=(submitter&&submitter.formTarget||form.target||'_self').toLowerCase();
  if(method!=='get'||(target&&target!=='_self'))return;
  const action=safeAppUrl(submitter&&submitter.formAction||form.action||window.location.href);
  if(!action||!safeAppUrl(window.location.href))return;
  rememberCurrent();
 },false);

 backLink.addEventListener('click',function(event){
  if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
  event.preventDefault();
  event.stopPropagation();
  let destination=null;
  try{destination=safeAppUrl(sessionStorage.getItem(previousKey))}catch(_){}
  clearPrevious();
  const current=safeAppUrl(window.location.href);
  if(!destination||!current||destination.pathname+destination.search===current.pathname+current.search){
   destination=new URL('dashboard.php',window.location.href);
  }
  if(typeof window.imNavigateWithWave==='function')window.imNavigateWithWave(destination.href);
  else window.location.assign(destination.href);
 });

 window.addEventListener('pagehide',function(){if(!internalDeparture)clearPrevious()});
 window.addEventListener('pageshow',function(event){
  if(event.persisted){
   internalDeparture=false;
   clearPrevious();
   try{sessionStorage.setItem('im-ocean-transition-arrival',JSON.stringify({to:window.location.href,at:Date.now()}))}catch(_){}
   window.location.reload();
  }
 });
})();
