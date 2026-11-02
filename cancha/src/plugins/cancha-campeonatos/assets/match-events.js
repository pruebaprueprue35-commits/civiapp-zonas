document.addEventListener('DOMContentLoaded',()=>{
 const button=document.querySelector('#cancha-add-player'),target=document.querySelector('#cancha-event-rows'),template=document.querySelector('#cancha-player-template');
 if(!button||!target||!template)return;
 let index=target.children.length;
 button.addEventListener('click',()=>{
  if(index>=70){button.disabled=true;return;}
  const html=template.innerHTML.replaceAll('__INDEX__',String(index++));
  target.insertAdjacentHTML('beforeend',html);
 });
});
