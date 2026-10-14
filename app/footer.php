</main><footer>Inventory Manager · IT Assets & Software</footer>
<script>
(function(){
 const header=document.querySelector('.topbar'),dropdown=document.querySelector('.menu-dropdown'),menuToggle=document.getElementById('menu-toggle'),nav=document.getElementById('primary-nav'),searchToggle=document.querySelector('.search-toggle'),searchForm=document.getElementById('global-search'),searchInput=document.getElementById('global-search-query'),compact=window.matchMedia('(max-width: 850px)');
 if(!header||!dropdown||!menuToggle||!nav||!searchToggle||!searchForm)return;
 function setMenu(open){menuToggle.setAttribute('aria-expanded',String(open));nav.hidden=!open}
 function setSearch(open){header.classList.toggle('search-open',open);searchToggle.setAttribute('aria-expanded',String(open));searchToggle.setAttribute('aria-label',open?'Close Search Inventory':'Open Search Inventory');if(open&&compact.matches)searchInput.focus()}
 menuToggle.addEventListener('click',function(){setMenu(menuToggle.getAttribute('aria-expanded')!=='true')});
 menuToggle.addEventListener('keydown',function(event){if(event.key!=='ArrowDown'&&event.key!=='ArrowUp')return;event.preventDefault();setMenu(true);const links=nav.querySelectorAll('a');(event.key==='ArrowUp'?links[links.length-1]:links[0])?.focus()});
 nav.addEventListener('keydown',function(event){const links=Array.from(nav.querySelectorAll('a')),index=links.indexOf(document.activeElement);if(index<0)return;let next=null;if(event.key==='ArrowDown')next=(index+1)%links.length;else if(event.key==='ArrowUp')next=(index+links.length-1)%links.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=links.length-1;if(next!==null){event.preventDefault();links[next].focus()}});
 nav.addEventListener('click',function(event){if(event.target.closest('a'))setMenu(false)});
 document.addEventListener('pointerdown',function(event){if(menuToggle.getAttribute('aria-expanded')==='true'&&!dropdown.contains(event.target))setMenu(false)});
 searchToggle.addEventListener('click',function(){setSearch(searchToggle.getAttribute('aria-expanded')!=='true')});
 document.addEventListener('keydown',function(event){if(event.key!=='Escape')return;if(menuToggle.getAttribute('aria-expanded')==='true'){event.preventDefault();setMenu(false);menuToggle.focus();return}if(searchToggle.getAttribute('aria-expanded')==='true'){event.preventDefault();setSearch(false);searchToggle.focus()}});
 if(compact.addEventListener)compact.addEventListener('change',function(){if(!compact.matches)setSearch(false)});else compact.addListener(function(){if(!compact.matches)setSearch(false)});
})();
(function(){const tabs=Array.from(document.querySelectorAll('.profile-tabs [role="tab"]'));if(tabs.length){function activate(tab,focus){tabs.forEach(function(item){const selected=item===tab;item.setAttribute('aria-selected',String(selected));item.setAttribute('tabindex',selected?'0':'-1');item.classList.toggle('active',selected);const panel=document.getElementById(item.getAttribute('aria-controls'));if(panel)panel.hidden=!selected});if(focus)tab.focus();history.replaceState(null,'',tab.getAttribute('href').split('#')[0]+'#'+tab.getAttribute('aria-controls'))}tabs.forEach(function(tab,index){tab.addEventListener('click',function(event){if(event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;event.preventDefault();activate(tab,false)});tab.addEventListener('keydown',function(event){if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:tabs.length-1))%tabs.length;activate(tabs[next],true)})})}
})();
</script></body></html>
