(function(){
 'use strict';
 const overlay=document.getElementById('im-ocean-transition');
 if(!overlay)return;

 const arrivalKey='im-ocean-transition-arrival';
 const leaveDuration=210;
 const revealDuration=180;
 const outgoingWaveDuration=210;
 const incomingWaveDuration=180;
 const waveStagger=10;
 const hiddenWaveY=110;
 const coveredWaveY=-72;
 const clearWaveY=-210;
 const routes=new Set(['dashboard.php','products.php','inventory.php','category-inventory.php','inventory-details.php','hardware-assets.php','assignments.php','employees.php','employee.php','categories.php','orders.php','vendors.php','vendor.php','history.php','users.php','audit_logs.php','credentials.php','credential-access.php','search.php']);
 const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
 const backdrop=overlay.querySelector('.im-ocean-transition__backdrop');
 const waves=Array.from(overlay.querySelectorAll('.im-ocean-transition__wave'));
 const requestFrame=window.requestAnimationFrame?window.requestAnimationFrame.bind(window):function(fn){return window.setTimeout(function(){fn(performance.now())},16)};
 const cancelFrame=window.cancelAnimationFrame?window.cancelAnimationFrame.bind(window):window.clearTimeout.bind(window);
 let pending=false,navigationStarted=false,frameId=0,watchdog=0,destination='',revealing=false;

 function eligibleUrl(value){
  try{
   const url=new URL(value,window.location.href);
   const basePath=window.location.pathname.slice(0,window.location.pathname.lastIndexOf('/')+1);
   if((url.protocol!=='http:'&&url.protocol!=='https:')||url.origin!==window.location.origin||!url.pathname.startsWith(basePath))return null;
   if(!routes.has(url.pathname.slice(basePath.length))||url.href===window.location.href)return null;
   return url;
  }catch(_){return null}
 }
 function storeDestination(url){
  try{sessionStorage.setItem(arrivalKey,JSON.stringify({to:url.href,at:Date.now()}));return true}catch(_){return false}
 }
 function clearDestination(){try{sessionStorage.removeItem(arrivalKey)}catch(_){}}
 function setY(element,value){element.style.transform='translate3d(0, '+value+'vh, 0)'}
 function easeOut(progress){return 1-Math.pow(1-progress,3)}
 function easeInOut(progress){return progress<.5?4*progress*progress*progress:1-Math.pow(-2*progress+2,3)/2}
 function stopFrame(){if(frameId){cancelFrame(frameId);frameId=0}}
 function resetOverlay(){
  stopFrame();
  revealing=false;
  overlay.classList.remove('is-leaving','is-arriving');
  overlay.style.display='none';
  backdrop.style.transform='';
  waves.forEach(function(wave){wave.style.transform=''});
  document.documentElement.classList.remove('im-ocean-arriving');
 }
 function failNavigation(){
  window.clearTimeout(watchdog);watchdog=0;pending=false;destination='';clearDestination();resetOverlay();
 }
 function assignDestination(url){
  navigationStarted=true;
  if(!storeDestination(url)){failNavigation();try{window.location.assign(url.href)}catch(_){}}
  else{
   const source=window.location.href,requestedAt=Date.now();
   function checkNavigation(){
    if(window.location.href!==source&&Date.now()-requestedAt<120000)watchdog=window.setTimeout(checkNavigation,5000);
    else failNavigation();
   }
   watchdog=window.setTimeout(checkNavigation,5000);
   try{window.location.assign(url.href)}catch(_){failNavigation();try{window.location.href=url.href}catch(__){}}
  }
 }
 function animate(kind,onComplete){
  stopFrame();
  const outgoing=kind==='out';
  const started=performance.now();
  const total=outgoing?leaveDuration:revealDuration;
  const initialWaveY=outgoing?hiddenWaveY:coveredWaveY;
  const finalWaveY=outgoing?coveredWaveY:clearWaveY;
  const initialBackdrop=outgoing?100:0;
  const finalBackdrop=outgoing?0:-100;
  const layerDuration=outgoing?outgoingWaveDuration:incomingWaveDuration;
  const curve=outgoing?easeOut:easeInOut;
  function draw(now){
   const elapsed=Math.max(0,now-started);
   waves.forEach(function(wave,index){
    const progress=Math.min(1,Math.max(0,(elapsed-index*waveStagger)/layerDuration));
    setY(wave,initialWaveY+(finalWaveY-initialWaveY)*curve(progress));
   });
   const backdropProgress=Math.min(1,elapsed/total);
   setY(backdrop,initialBackdrop+(finalBackdrop-initialBackdrop)*curve(backdropProgress));
   if(elapsed>=total){frameId=0;onComplete();return}
   frameId=requestFrame(draw);
  }
  frameId=requestFrame(draw);
 }
 function navigateAfterCover(){
  if(!pending||!destination)return;
  pending=false;
  const url=new URL(destination);
  assignDestination(url);
 }
 function start(url,allowImmediateFallback){
  if(pending)return true;
  if(reducedMotion.matches){if(allowImmediateFallback){try{window.location.assign(url.href)}catch(_){}}return allowImmediateFallback}
  if(!storeDestination(url)){if(allowImmediateFallback){try{window.location.assign(url.href)}catch(_){}}return allowImmediateFallback}
  window.clearTimeout(watchdog);watchdog=0;
  stopFrame();revealing=false;pending=true;navigationStarted=false;destination=url.href;
  window.clearTimeout(window.__imOceanFailsafe);document.documentElement.classList.remove('im-ocean-arriving');
  overlay.style.display='block';
  overlay.classList.remove('is-arriving');
  overlay.classList.add('is-leaving');
  setY(backdrop,100);waves.forEach(function(wave){setY(wave,hiddenWaveY)});
  animate('out',navigateAfterCover);
  return true;
 }

 window.imNavigateWithWave=function(value){
  const url=eligibleUrl(value);
  if(!url){try{const direct=new URL(value,window.location.href);if(direct.origin===window.location.origin)window.location.assign(direct.href)}catch(_){}return true}
  return start(url,true);
 };

 document.addEventListener('click',function(event){
  if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
  const link=event.target.closest&&event.target.closest('a[href]');
  if(!link||link.hasAttribute('data-app-back')||link.hasAttribute('download')||link.relList.contains('external')||(link.target&&link.target.toLowerCase()!=='_self'))return;
  const raw=link.getAttribute('href');
  if(!raw||raw.includes('#'))return;
  const url=eligibleUrl(link.href);
  if(!url)return;
  if(start(url,false))event.preventDefault();
 },true);

 function finishReveal(){
  revealing=false;resetOverlay();
 }
 function startReveal(){
  if(revealing||!document.documentElement.classList.contains('im-ocean-arriving'))return;
  if(reducedMotion.matches){resetOverlay();return}
  window.clearTimeout(window.__imOceanFailsafe);revealing=true;
  overlay.style.display='block';overlay.classList.remove('is-leaving');overlay.classList.add('is-arriving');
  setY(backdrop,0);waves.forEach(function(wave){setY(wave,coveredWaveY)});
  animate('in',finishReveal);
 }
 function afterDocumentReady(){
  if(document.readyState==='loading'||document.readyState==='interactive')document.addEventListener('DOMContentLoaded',startReveal,{once:true});
  else requestFrame(startReveal);
 }

 if(document.documentElement.classList.contains('im-ocean-arriving')){
  window.clearTimeout(window.__imOceanFailsafe);
  if(reducedMotion.matches){document.documentElement.classList.remove('im-ocean-arriving');overlay.style.display='none'}
  else{
   overlay.style.display='block';backdrop.style.transform='translate3d(0, 0, 0)';waves.forEach(function(wave){setY(wave,coveredWaveY)});
   afterDocumentReady();
  }
 }else window.clearTimeout(window.__imOceanFailsafe);

 window.addEventListener('pageshow',function(event){
  if(!event.persisted||reducedMotion.matches)return;
  document.documentElement.classList.add('im-ocean-arriving');overlay.style.display='block';
  backdrop.style.transform='translate3d(0, 0, 0)';waves.forEach(function(wave){setY(wave,coveredWaveY)});
  requestFrame(startReveal);
 });
 window.addEventListener('pagehide',function(){
  if(pending&&!navigationStarted)clearDestination();
  if(watchdog){window.clearTimeout(watchdog);watchdog=0}
  stopFrame();
 });
 document.addEventListener('visibilitychange',function(){
  if(document.hidden&&pending){stopFrame();setY(backdrop,0);waves.forEach(function(wave){setY(wave,coveredWaveY)});navigateAfterCover()}
 });
 if(reducedMotion.addEventListener)reducedMotion.addEventListener('change',function(event){
  if(!event.matches)return;
  if(pending&&destination){const url=new URL(destination);pending=false;stopFrame();assignDestination(url)}
  else if(revealing)finishReveal();
  else resetOverlay();
 });
})();
