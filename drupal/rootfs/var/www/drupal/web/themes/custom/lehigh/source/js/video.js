/**
 *  @file video.js
 *
 *  Handles .field_media_video_file videos.
 *
 *  Attaches play and pause listeners to alternative controls.
 *  Provides a timer which checks the play state of the video, and adds appropriate classes.
 *  This allows the alternative controls to handled via SASS. It also allows the alternative controls to be sensistive
 *  when the play state is changed by the standard controls.
 *
 */


// Select HTML5 video
const videos = document.querySelectorAll(".field_media_video_file");


for (i=0; i < videos.length; i++) {
  const container = videos[i];
  const video = container.querySelector('video');
  
  if (video !== null) {
    const controls = video.parentElement.querySelector('.control-container');
  
    if (controls !== null && video.hasAttribute('controls') && video.getAttribute('controls') === 'controls') {
        container.classList.add('controls');
        const playBtn = controls.parentElement.querySelector(".play");
        const pauseBtn = controls.querySelector(".pause");
    
      // see https://developer.chrome.com/blog/autoplay/
      if (video.hasAttribute("autoplay") && video.hasAttribute('muted')) {
        controls.classList.remove('paused');
        controls.classList.add('playing');
      }
      // pause or play the video
      const play = (e) => {
        // Condition when to play a video
        if (video.paused) {
          if (controls.classList.contains('paused')) { // Prevents play/pause race conditions that throw console errors.
            video.play()
          }
        } else {
          if (controls.classList.contains('playing')) {
            video.pause();
          }
        }
      }
      
      // Detects the current playstate
      
      function isPlaying(player, controlElement) {
        if (player.currentTime > 0 && !player.paused && !player.ended
          && player.readyState > player.HAVE_CURRENT_DATA) {
          controlElement.classList.remove('paused');
          controlElement.classList.add('playing');
        } else {
          controlElement.classList.remove('playing');
          controlElement.classList.add('paused');
        }
      }
      
      // Audit the video player’s play state on a timer
      
      var playerState = setInterval(isPlaying, 100, video, controls);
      
      // Attach play/pause as listeners
      
      playBtn.addEventListener('click', play);
      pauseBtn.addEventListener('click', play);
      
      if ('ontouchstart' in window) {
        playBtn.addEventListener('ontouchstart', play);
        pauseBtn.addEventListener('ontouchstart', play);
      }
    }
  }
}
/**
// set the pause button to display:none by default
document.querySelector(".fa-pause").style.display = "none"
// update the progress bar
video.addEventListener("timeupdate", () => {
  let curr = (video.currentTime / video.duration) * 100
  if(video.ended){
    document.querySelector(".fa-play").style.display = "block"
    document.querySelector(".fa-pause").style.display = "none"
  }
  document.querySelector('.inner').style.width = `${curr}%`
})

// trigger fullscreen
const fullScreen = (e) => {
  e.preventDefault()
  video.requestFullscreen()
}
// download the video
const download = (e) => {
  e.preventDefault()
  let a = document.createElement('a')
  a.href = video.src
  a.target = "_blank"
  a.download = ""
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}
// rewind the current time
const rewind = (e) => {
  video.currentTime = video.currentTime - ((video.duration/100) * 5)
}
// forward the current time
const forward = (e) => {
  video.currentTime = video.currentTime + ((video.duration/100) * 5)
}
**/
