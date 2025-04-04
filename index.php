<?php
// index.php

// Start session with a long lifetime
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(10 * 365 * 24 * 60 * 60);
    session_start();
}
include('config.php');

// Update last_seen for logged-in user
if (isset($_SESSION['user_id'])) {
    $currentUserId = (int) $_SESSION['user_id'];
    $conn->query("UPDATE users SET last_seen = NOW() WHERE id = $currentUserId");
}
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Auto-update empty display name for logged‑in users
if ($userId > 0) {
    $userResult = $conn->query("SELECT * FROM users WHERE id = $userId");
    if ($userResult && $userResult->num_rows > 0) {
        $userData = $userResult->fetch_assoc();
        if (empty($userData['display_name'])) {
            $conn->query("UPDATE users SET display_name = username WHERE id = $userId");
        }
    }
}

// Check if user is banned
$userBanned = false;
if ($userId > 0) {
    $userCheckResult = $conn->query("SELECT banned FROM users WHERE id = $userId");
    if ($userCheckResult && $userCheckResult->num_rows > 0) {
        $userData = $userCheckResult->fetch_assoc();
        if ($userData['banned'] == 1) {
            $userBanned = true;
        }
    }
}
$feed = isset($_GET['feed']) ? $_GET['feed'] : 'foryou';
if ($userId == 0 && $feed === 'following') {
    $feed = 'foryou';
}
$specifiedVideoId = isset($_GET['video_id']) ? intval($_GET['video_id']) : 0;

// Pagination variables
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// NEW: Exclude already loaded video IDs if provided by AJAX
$exclude_sql = "";
if (isset($_GET['exclude']) && !empty($_GET['exclude'])) {
    $exclude_ids = explode(',', $_GET['exclude']);
    $exclude_ids = array_map('intval', $exclude_ids);
    $exclude_str = implode(',', $exclude_ids);
    $exclude_sql = " AND v.id NOT IN ($exclude_str) ";
}

// Build the base SQL query (same for both feeds)
if ($feed === 'following' && $userId > 0) {
    $baseSQL = "SELECT 
      v.id, v.video_path, v.images, v.title, v.hashtags, v.view_count,
      v.thumbnail, v.music_url, u.display_name, u.profile_image, u.id AS user_id,
      u.verified,
      (SELECT COUNT(*) FROM likes WHERE likes.video_id = v.id) AS like_count,
      (SELECT COUNT(*) FROM comments WHERE comments.video_id = v.id) AS comment_count,
      (SELECT COUNT(*) FROM favorites WHERE favorites.video_id = v.id) AS favorite_count
    FROM videos v
    JOIN users u ON v.user_id = u.id
    JOIN follows f ON f.following_id = v.user_id
    WHERE f.follower_id = $userId
      AND (u.is_private = 0 OR u.id = $userId)
      AND u.banned = 0
      $exclude_sql
    ORDER BY (v.id = $specifiedVideoId) DESC, v.id DESC";
} else {
    $baseSQL = "SELECT 
      v.id, v.video_path, v.images, v.title, v.hashtags, v.view_count,
      v.thumbnail, v.music_url, u.display_name, u.profile_image, u.id AS user_id,
      u.verified,
      (SELECT COUNT(*) FROM likes WHERE likes.video_id = v.id) AS like_count,
      (SELECT COUNT(*) FROM comments WHERE comments.video_id = v.id) AS comment_count,
      (SELECT COUNT(*) FROM favorites WHERE favorites.video_id = v.id) AS favorite_count
    FROM videos v
    JOIN users u ON v.user_id = u.id 
    WHERE (u.is_private = 0 OR u.id = $userId)
      AND u.banned = 0
      $exclude_sql
    ORDER BY CASE WHEN v.id = $specifiedVideoId THEN 1 ELSE 0 END DESC, RAND()";
}
$sql = $baseSQL . " LIMIT $offset, $limit";
$result = $conn->query($sql);
if (!$result) {
    die("Error in main query: " . $conn->error);
}

// Get likes and favorites for current user
$userLikes = [];
if ($userId > 0) {
    $likeResult = $conn->query("SELECT video_id FROM likes WHERE user_id = $userId");
    if ($likeResult) {
        while ($row = $likeResult->fetch_assoc()) {
            $userLikes[] = $row['video_id'];
        }
    }
}
$userFavorites = [];
if ($userId > 0) {
    $favResult = $conn->query("SELECT video_id FROM favorites WHERE user_id = $userId");
    if ($favResult) {
        while ($row = $favResult->fetch_assoc()) {
            $userFavorites[] = $row['video_id'];
        }
    }
}

function getProfileImage($img) {
    return !empty($img) ? htmlspecialchars($img) : 'default-profile.png';
}
function convertLink($link) {
    return str_replace(
        "https://github.com/chrstianjames/ch/releases/download/",
        "https://redtok.x10.bz/videoshost/",
        $link
    );
}

// If AJAX request, return next 10 video items as JSON and exit.
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $videosHTML = [];
    while($video = $result->fetch_assoc()){
        ob_start();
        $liked = ($userId > 0) ? in_array($video['id'], $userLikes) : false;
        $favorited = ($userId > 0) ? in_array($video['id'], $userFavorites) : false;
        $profileImage = htmlspecialchars(convertLink(getProfileImage($video['profile_image'])));
        $posterImage = !empty($video['thumbnail']) ? htmlspecialchars(convertLink($video['thumbnail'])) : 'default-thumbnail.jpg';
        $isPhotoPost = empty($video['video_path']) && !empty($video['images']);
        $postImages = (!empty($video['images'])) ? json_decode($video['images'], true) : [];
        ?>
        <div class="video-item" data-video-id="<?php echo $video['id']; ?>"
             data-userid="<?php echo $video['user_id']; ?>"
             data-username="<?php echo htmlspecialchars($video['display_name']); ?>"
             data-title="<?php echo htmlspecialchars($video['title']); ?>"
             data-verified="<?php echo $video['verified']; ?>"
             data-isphotopost="<?php echo $isPhotoPost ? '1' : '0'; ?>">
          
          <?php if ($isPhotoPost && !empty($postImages) && is_array($postImages)): ?>
            <div class="photo-badge" style="position:absolute; top:5px; right:5px; background:#ff3b30; padding:2px 6px; border-radius:3px; font-size:12px;">Photos</div>
            <?php if (count($postImages) > 1): ?>
              <div class="image-slider">
                <div class="slides">
                  <?php foreach ($postImages as $imgPath): 
                        $imgPath = htmlspecialchars(convertLink($imgPath)); ?>
                    <img src="<?php echo $imgPath; ?>" alt="Post Image" class="slide">
                  <?php endforeach; ?>
                </div>
                <div class="slide-counter">1/<?php echo count($postImages); ?></div>
              </div>
            <?php else: 
                  $singleImage = htmlspecialchars(convertLink($postImages[0])); ?>
              <div class="single-image">
                <img src="<?php echo $singleImage; ?>" alt="Post Image">
              </div>
            <?php endif; ?>
            <?php if (!empty($video['music_url'])): ?>
              <audio class="bg-music" src="<?php echo htmlspecialchars(convertLink($video['music_url'])); ?>" preload="auto" loop></audio>
            <?php endif; ?>
          <?php else: ?>
          <video preload="auto" src="<?php echo htmlspecialchars(convertLink($video['video_path'])); ?>" poster="<?php echo $posterImage; ?>" loop playsinline muted>
            Your browser does not support the video tag.
          </video>
          <?php endif; ?>

          <div class="video-progress">
  <div class="video-progress-fill"></div>
  <div class="seek-handle"></div>
</div>
<!-- Time display (static at bottom center) -->
<div class="seek-time-display"></div>
          <div class="sound-toggle" onclick="toggleSound(event, this)">
            <img src="https://img.icons8.com/ios-filled/50/ffffff/mute.png" alt="Sound">
          </div>
          <div class="right-icons">
            <div class="profile-pic">
              <a href="<?php echo ($userId > 0) ? "user.php?user_id=".$video['user_id'] : "login.php"; ?>">
                <img src="<?php echo $profileImage; ?>" alt="Profile">
              </a>
              <?php if($userId > 0 && $video['user_id'] != $userId):
                     $isFollowing = false;
                     $followCheckRes = $conn->query("SELECT COUNT(*) as cnt FROM follows WHERE follower_id = $userId AND following_id = " . $video['user_id']);
                     if($followCheckRes){
                       $followCheckRow = $followCheckRes->fetch_assoc();
                       $isFollowing = ($followCheckRow['cnt'] > 0);
                     }
                     if(!$isFollowing): ?>
                       <div class="follow-button" onclick="toggleFollow(<?php echo $video['user_id']; ?>, this)">+</div>
              <?php endif; endif; ?>
            </div>
            <div class="icon-button like-button <?php echo $liked ? 'red' : 'white'; ?>" 
                 data-video-id="<?php echo $video['id']; ?>" 
                 onclick="toggleLike(this)">
              <img src="<?php echo $liked ? 'https://img.icons8.com/fluency/48/ff0000/like.png' : 'https://img.icons8.com/ios-filled/50/ffffff/like.png'; ?>" alt="Like">
              <span class="like-count"><?php echo $video['like_count']; ?></span>
            </div>
            <div class="icon-button comment-button" onclick="openComments(<?php echo $video['id']; ?>)">
              <img src="https://img.icons8.com/ios-filled/50/ffffff/speech-bubble.png" alt="Comments">
              <span><?php echo $video['comment_count']; ?></span>
            </div>
            <div class="icon-button favorite-button" 
                 data-video-id="<?php echo $video['id']; ?>" 
                 onclick="toggleFavorite(this)">
              <img src="<?php echo $favorited ? 'https://img.icons8.com/ios-filled/50/ffcc00/bookmark-ribbon.png' : 'https://img.icons8.com/ios/50/ffffff/bookmark-ribbon--v1.png'; ?>" alt="Favorite">
              <span class="favorite-count"><?php echo $video['favorite_count']; ?></span>
            </div>
            <div class="icon-button share-button" 
                 onclick="shareVideo('<?php echo $video['id']; ?>', <?php echo ($video['user_id'] == $userId ? 'true' : 'false'); ?>)">
              <img src="share.png" alt="Share">
              <span>Share</span>
            </div>
          </div>
        </div>
        <?php
        $videosHTML[] = ob_get_clean();
    }
    echo json_encode($videosHTML);
    exit;
}
?>
<?php include 'header.php'; ?>
<!DOCTYPE html>
<html>
<head>
  <script>
document.addEventListener('click', function(e) {
  let target = e.target;
  while (target && target.tagName !== 'A') {
    target = target.parentElement;
  }
  if (target && localStorage.getItem('uploadInProgress') === 'true') {
    e.preventDefault();
    // Show your custom modal immediately:
    showCustomWarning(function(shouldLeave) {
      if (shouldLeave) {
        localStorage.removeItem('uploadInProgress');
        window.location.href = target.href;
      }
    });
  }
});

function showCustomWarning(callback) {
  const modal = document.createElement('div');
  modal.style.position = 'fixed';
  modal.style.top = '0';
  modal.style.left = '0';
  modal.style.width = '100%';
  modal.style.height = '100%';
  modal.style.backgroundColor = 'rgba(0, 0, 0, 0.6)';
  modal.style.display = 'flex';
  modal.style.alignItems = 'center';
  modal.style.justifyContent = 'center';
  modal.style.zIndex = '10000';
  
  modal.innerHTML = `
    <div style="
          background: #fff;
          border-radius: 8px;
          width: 90%;
          max-width: 400px;
          padding: 24px;
          box-shadow: 0 4px 12px rgba(0,0,0,0.2);
          font-family: sans-serif;
          text-align: center;">
      <h2 style="margin-top: 0; font-size: 22px; color: #333;">Upload in Progress</h2>
      <p style="font-size: 16px; color: #555; margin: 16px 0;">
        Leaving this page will cancel your video upload.
      </p>
      <div style="display: flex; gap: 16px;">
        <button id="leaveBtn" style="
                flex: 1;
                padding: 12px;
                font-size: 16px;
                background-color: #d32f2f;
                color: #fff;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
          Leave
        </button>
        <button id="stayBtn" style="
                flex: 1;
                padding: 12px;
                font-size: 16px;
                background-color: #388e3c;
                color: #fff;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
          Stay
        </button>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  
  modal.querySelector('#leaveBtn').addEventListener('click', function() {
    modal.remove();
    callback(true);
  });
  
  modal.querySelector('#stayBtn').addEventListener('click', function() {
    modal.remove();
    callback(false);
  });
}

window.addEventListener('beforeunload', function(e) {
  if (localStorage.getItem('uploadInProgress') === 'true') {
    const confirmationMessage = "A video upload is in progress. Leaving will cancel the upload.";
    (e || window.event).returnValue = confirmationMessage;
    return confirmationMessage;
  }
});

// Remove the flag if the page is actually unloaded.
window.addEventListener('unload', function() {
  localStorage.removeItem('uploadInProgress');
});


  // Function to show the success toast.
  function showUploadSuccessToast() {
    const toast = document.createElement('div');
    toast.id = 'uploadSuccessToast';
    toast.style.position = 'fixed';
    toast.style.bottom = '30px';
    toast.style.left = '50%';
    toast.style.transform = 'translateX(-50%)';
    toast.style.backgroundColor = '#4CAF50'; // Green background for success
    toast.style.color = '#fff';
    toast.style.padding = '16px 24px';
    toast.style.borderRadius = '8px';
    toast.style.boxShadow = '0 4px 6px rgba(0,0,0,0.3)';
    toast.style.fontFamily = '"Roboto", sans-serif';
    toast.style.fontSize = '16px';
    toast.style.zIndex = '10000';
    toast.innerHTML = `<span style="margin-right:8px; font-size:20px;">&#10003;</span>Upload successful!`;
    document.body.appendChild(toast);
    setTimeout(() => {
      toast.style.transition = 'opacity 0.5s ease-out';
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 9000);
    }, 10000);
  }

  // Listen for storage events so that if another window/frame sets uploadSuccess, the toast appears immediately.
  window.addEventListener('storage', function(e) {
    if (e.key === 'uploadSuccess' && e.newValue === 'true') {
      showUploadSuccessToast();
      localStorage.removeItem('uploadSuccess');
    }
  });
  
  // Also check on DOMContentLoaded in case the flag is already set.
  document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('uploadSuccess') === 'true') {
      showUploadSuccessToast();
      localStorage.removeItem('uploadSuccess');
    }
  });
  
document.addEventListener('DOMContentLoaded', function() {
  const progressIndicator = document.getElementById('uploadProgressIndicator');
  const progressCircle = document.getElementById('uploadProgressCircle');
  const progressPercent = document.getElementById('uploadProgressPercent');
  const circumference = 176; // For a circle with r=28

  function updateProgressIndicator() {
    // Only show progress if an upload is in progress.
    if (localStorage.getItem('uploadInProgress') !== 'true') {
      progressIndicator.style.display = 'none';
      localStorage.removeItem('uploadProgress');
      return;
    }

    let progress = localStorage.getItem('uploadProgress');
    if (progress !== null) {
      progressIndicator.style.display = 'block';
      progress = Number(progress);
      const offset = circumference - (progress / 100) * circumference;
      progressCircle.style.strokeDashoffset = offset;
      progressPercent.textContent = progress + '%';

      // When complete, hide indicator after a short delay and clear the progress.
      if (progress >= 100) {
        setTimeout(() => {
          progressIndicator.style.display = 'none';
          localStorage.removeItem('uploadProgress');
        }, 1000);
      }
    } else {
      progressIndicator.style.display = 'none';
    }
  }
  
  // Poll every half second.
  setInterval(updateProgressIndicator, 500);
});
</script>


  <meta charset="utf-8">
  <title>RedTok Shorts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <script src="/javascript/global-variables.php"></script>
  <style>
/* Material Design Inspired CSS for Android APK Look with New Layout */
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');
@import url('https://fonts.googleapis.com/icon?family=Material+Icons');

:root {
  --bg-color: #121212;
  --surface-color: #1e1e1e;
  --primary-color: #03a9f4;
  --accent-color: #ff9800;
  --text-color: #e0e0e0;
  --secondary-text: #b0bec5;
  --icon-size: 26px;
  --nav-height: 50px;
  --bottom-nav-height: 60px;
  --elevation-shadow: 0 2px 4px rgba(0, 0, 0, 0.6);
  --font-family: 'Roboto', sans-serif;
  /* New layout variables */
  --layout-bg-gradient: linear-gradient(135deg, #121212, #1e1e1e);
  --card-bg: #1e1e1e;
  --card-shadow: 0 2px 6px rgba(0, 0, 0, 0.8);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
}

html,
body {
  width: 100%;
  height: 100%;
  font-family: var(--font-family);
  background: var(--layout-bg-gradient);
  color: var(--text-color);
  overflow: hidden;
}

/* New layout container for the entire page */
.page-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

/* TOP NAVIGATION */
.top-nav {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: var(--nav-height);
  background: var(--surface-color);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 15px;
  border-bottom: 1px solid #333;
  box-shadow: var(--elevation-shadow);
  z-index: 1000;
}

.brand {
  color: var(--accent-color);
  font-size: 18px;
  font-weight: bold;
}

.tabs {
  display: flex;
  gap: 20px;
  justify-content: center;
  align-items: center;
  flex: 1;
  margin: 0 20px;
}

.tabs a {
  text-decoration: none;
  color: #888;
  font-weight: bold;
  font-size: 15px;
  padding-bottom: 2px;
  transition: color 0.3s;
  border-bottom: 2px solid transparent;
}

.tabs a.active,
.tabs a:hover {
  color: var(--text-color);
  border-bottom: 2px solid var(--text-color);
}

.search-icon {
  cursor: pointer;
  display: flex;
  align-items: center;
}

.search-icon img,
.search-icon .material-icons {
  width: var(--icon-size);
  height: var(--icon-size);
  filter: invert(100%);
}

/* BOTTOM NAVIGATION */
.bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  height: var(--bottom-nav-height);
  background: var(--surface-color);
  border-top: 1px solid #333;
  display: flex;
  justify-content: space-around;
  align-items: center;
  box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.6);
  z-index: 2000;
}

.bottom-nav .nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 12px;
  color: #ccc;
  cursor: pointer;
}

.bottom-nav .nav-item img,
.bottom-nav .nav-item .material-icons {
  width: var(--icon-size);
  height: var(--icon-size);
  margin-bottom: 2px;
  filter: invert(100%);
}

.bottom-nav .nav-item span {
  font-size: 12px;
  color: #ccc;
}

.bottom-nav .nav-item.notifications {
  position: relative;
}

.bottom-nav .nav-item.notifications .badge {
  background: #f44336;
  color: #fff;
  border-radius: 50%;
  font-size: 10px;
  padding: 2px 6px;
  position: absolute;
  top: -5px;
  right: -10px;
  display: none;
}

/* Bottom Overlay (Post Details) */
#bottom-overlay {
  position: fixed;
  bottom: var(--bottom-nav-height);
  left: 0;
  width: 100%;
  padding: 10px 15px;
  z-index: 1100;
  pointer-events: none;
}

#bottom-overlay .username,
#bottom-overlay .title,
#bottom-overlay a,
#bottom-overlay .see-more {
  pointer-events: auto;
}

#bottom-overlay .username {
  font-weight: bold;
  font-size: 18px;
  margin-bottom: 4px;
}

#bottom-overlay .username a {
  text-decoration: none;
  color: var(--text-color);
}

#bottom-overlay .title {
  font-size: 16px;
  margin-bottom: 4px;
}

/* FEED CONTAINER */
.feed-container {
  position: absolute;
  top: var(--nav-height);
  bottom: var(--bottom-nav-height);
  width: 100%;
  overflow-y: scroll;
  scroll-snap-type: y mandatory;
  -webkit-overflow-scrolling: touch;
  scroll-behavior: auto;
  overscroll-behavior: contain;
  background: var(--bg-color);
}

/* VIDEO ITEM & ELEMENT */
.video-item {
  position: relative;
  width: 100%;
  height: 100%;
  scroll-snap-align: start;
  scroll-snap-stop: always;
  background: var(--bg-color);
  overflow: hidden;
}

.video-item video {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 100%;
  transform: translate(-50%, -50%);
  object-fit: cover;
  object-position: center;
  display: block;
}

/* Photo Badge */
.photo-badge {
  position: absolute;
  top: 5px;
  right: 5px;
  z-index: 15;
}

/* Image Slider */
.image-slider {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.image-slider .slides {
  display: flex;
  width: 100%;
  height: 100%;
  transition: transform 0.3s ease;
}

.image-slider .slides img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center;
  flex-shrink: 0;
  background: #000;
}

.slide-counter {
  position: absolute;
  bottom: 90px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 14px;
  z-index: 10;
}

/* Single Image */
.single-image {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: #000;
}

.single-image img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center;
}

/* Sound Toggle */
.video-item .sound-toggle {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 15;
  cursor: pointer;
}

.video-item .sound-toggle img {
  width: 24px;
  height: 24px;
}

/* Right-side Icons */
.right-icons {
  position: absolute;
  right: 10px;
  bottom: 120px;
  z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
}

.profile-pic {
  position: relative;
}

.profile-pic img {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--text-color);
  cursor: pointer;
}

.icon-button {
  cursor: pointer;
  text-align: center;
}

.icon-button img {
  width: 30px;
  height: 30px;
}

.icon-button span {
  display: block;
  margin-top: 4px;
  font-size: 14px;
}

.follow-button {
  position: absolute;
  bottom: -5px;
  right: -5px;
  background: #ff3b30;
  color: #fff;
  border-radius: 50%;
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  cursor: pointer;
  border: 2px solid var(--bg-color);
  font-size: 14px;
  transition: transform 0.3s;
}

.follow-button:hover {
  transform: scale(1.1);
}

.follow-pulse {
  animation: pulse 0.6s forwards;
}

@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.5); }
  100% { transform: scale(1); }
}

.bg-music {
  display: none;
}

/* Upload Modal - Full Screen */
.upload-modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.9);
  z-index: 3000;
  align-items: center;
  justify-content: center;
  padding: 0; /* Remove extra padding for full screen */
}

.upload-modal .modal-content {
  width: 100%;
  height: 100%;
  background: #fff;
  border-radius: 0; /* Remove border-radius to span full screen */
  overflow: hidden;
  box-shadow: none;
}

.upload-modal .modal-header {
  padding: 12px 16px;
  background: #f7f7f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.upload-modal .modal-header h2 {
  font-size: 20px;
  margin: 0;
  color: #333;
}

.upload-modal .modal-header .close-btn {
  cursor: pointer;
  font-size: 24px;
  color: #333;
  line-height: 1;
}

.upload-modal iframe {
  width: 100%;
  height: calc(100% - 60px); /* Adjusted to account for header height */
  border: none;
}


/* Search & Notifications Overlays */
#searchOverlay,
#notificationsOverlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: #fff;
  color: #000;
  z-index: 3000;
  overflow-y: auto;
  padding: 0;
  margin: 0;
}

#searchOverlay .overlay-header {
  background: #f7f7f7;
  padding: 15px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #ccc;
}

#searchOverlay #searchInput {
  width: calc(100% - 50px);
  padding: 10px;
  font-size: 16px;
  border: 1px solid #ccc;
  border-radius: 4px;
}

#searchOverlay button {
  font-size: 20px;
  background: none;
  border: none;
  cursor: pointer;
}

#searchResults {
  padding: 10px;
}

#notificationsOverlay .overlay-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 15px;
  background: #f7f7f7;
  border-bottom: 1px solid #ccc;
}

#notificationsOverlay iframe {
  width: 100%;
  height: calc(100% - 60px);
  border: none;
}

.hashtag {
  font-family: 'Courier New', monospace;
  color: blue;
}

.see-more {
  color: var(--accent-color);
  cursor: pointer;
  pointer-events: auto;
}

/* Comments Modal Overlay */
.comments-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  z-index: 2000;
}

/* Bottom Sheet Modal (Comments) */
.comments-modal {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  max-height: 400%;
  background: #1e1e1e;
  border-top: 1px solid #333;
  overflow-y: auto;
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
}

.comments-modal .comments-header {
  display: flex;
  align-items: center;
  padding: 10px;
  background: #fff;
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
}

.comments-modal .comments-header h2 {
  font-size: 16px;
  color: #000;
}

.comments-modal iframe {
  width: 100%;
  border: none;
  height: 400px;
}

/* Banned Modal */
#bannedModal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.9);
  z-index: 5000;
  display: flex;
  align-items: center;
  justify-content: center;
}

#bannedModal .modal-content {
  background: #fff;
  color: #000;
  padding: 20px;
  border-radius: 8px;
  text-align: center;
  position: relative;
  max-width: 90%;
}

#bannedModal .modal-content button {
  margin-top: 15px;
  padding: 8px 16px;
  border: none;
  background: var(--accent-color);
  color: #fff;
  border-radius: 4px;
  cursor: pointer;
}

#bannedModal .close-btn {
  position: absolute;
  top: 5px;
  right: 5px;
  background: transparent;
  border: none;
  font-size: 24px;
  cursor: pointer;
}

.share-modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: flex-end; /* Modal slides up from bottom */
  justify-content: center;
  z-index: 3000;
  
}

/* The modal content container */
.share-modal .share-modal-content {
  background: #fff;
  width: 100%;
  max-width: 400px;
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
  padding: 20px;
  box-shadow: 0 -2px 6px rgba(0,0,0,0.2);
  position: relative;
}

.share-modal-content h3 {
  margin-bottom: 15px;
  font-size: 18px;
  text-align: center;
}

.share-option {
  padding: 15px;
  border-bottom: 1px solid #ddd;
  cursor: pointer;
  text-align: center;
  font-size: 16px;
}

.share-option:last-child {
  border-bottom: none;
}

.close-btn {
  position: absolute;
  top: 10px;
  right: 15px;
  background: transparent;
  border: none;
  font-size: 24px;
  cursor: pointer;
}

/* Chat Overlay & Modal */
.chat-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.8);
  z-index: 4000;
}

.chat-modal {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: #fff;
  display: flex;
  flex-direction: column;
}

.chat-header {
  padding: 10px 15px;
  background: #f7f7f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.chat-header h2 {
  margin: 0;
  font-size: 18px;
  color: #333;
}

.chat-header .close-btn {
  font-size: 24px;
  background: transparent;
  border: none;
  cursor: pointer;
}

.chat-modal iframe {
  flex: 1;
  border: none;
}

/* Video Progress Bar */
.video-progress {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 4px;
  background: rgba(255, 255, 255, 0.3);
  cursor: pointer;
  border-radius: 2px;
  transition: height 0.2s ease, background 0.2s ease;
}

.video-progress.dragging {
  height: 8px;
  background: rgba(255, 255, 255, 0.7);
}

.video-progress-fill {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  width: 0%;
  background-color: var(--accent-color);
  border-radius: 2px;
  transition: width 0.1s linear;
}

.seek-handle {
  position: absolute;
  top: 50%;
  width: 12px;
  height: 12px;
  background: #fff;
  border: 2px solid var(--accent-color);
  border-radius: 50%;
  transform: translate(-50%, -50%);
  display: none;
  transition: width 0.2s ease, height 0.2s ease;
  z-index: 6;
}

.seek-handle.dragging {
  width: 18px;
  height: 18px;
}

.seek-time-display {
  position: absolute;
  bottom: 90px;
  left: 50%;
  transform: translateX(-50%);
  font-size: 15px;
  color: #fff;
  background: rgba(0, 0, 0, 0.7);
  padding: 4px 8px;
  border-radius: 6px;
  pointer-events: none;
  display: none;
  z-index: 5;
  white-space: nowrap;
  text-align: center;
}

/* Wave Animation for Loading State */
@keyframes wave {
  0% {
    background-position: 0% 50%;
  }
  100% {
    background-position: 200% 50%;
  }
}

.video-progress.loading .video-progress-fill {
  background: linear-gradient(270deg, #ff9800, #ffffff, #ff9800);
  background-size: 200% 200%;
  animation: wave 2s infinite;
}

/* Sentinel Element for Auto-loading */
#loadMoreSentinel {
  height: 1px;
  width: 100%;
}

.pause-overlay {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: none;
  z-index: 100; /* ensure it's above the video */
}
.pause-overlay img {
  width: 50px; /* adjust as needed */
  cursor: pointer;
}

/* Remove Focus Outlines for Buttons and Links */
button:focus,
a:focus,
input:focus,
textarea:focus {
  outline: none !important;
  box-shadow: none !important;
}

button:active,
a:active,
input:active,
textarea:active {
  background-color: transparent !important;
}

  body, 
  * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
  }
  /* Make selection color transparent so that even if selection occurs it won’t show color */
  ::selection {
    background: transparent;
  }
  ::-moz-selection {
    background: transparent;
  }
  </style>
</head>
<body>
<div id="uploadProgressIndicator" style="
  position: fixed;
  top: 76px;
  right: 5px;
  z-index: 10000;
  display: none;">
  <svg width="60" height="60">
    <!-- Background circle: light grey for a subtle shadow effect -->
    <circle cx="30" cy="30" r="28" stroke="#BDBDBD" stroke-width="4" fill="none" />
    <!-- Progress circle (rotated -90° so it starts at the top) -->
    <circle id="uploadProgressCircle" cx="30" cy="30" r="28" stroke="#4CAF50" stroke-width="4" fill="none"
      stroke-dasharray="176" stroke-dashoffset="176"
      style="transform: rotate(-90deg); transform-origin: center;" />
  </svg>
  <div id="uploadProgressPercent" style="
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: #4CAF50;
      font-size: 14px;
      font-family: 'Roboto', sans-serif;
      font-weight: 500;">
  </div>
</div>

<div id="bottom-overlay"></div>
<?php if (!$userBanned): ?>
  <div class="feed-container" id="feedContainer">
    <?php 
      // Output the first 10 videos from the current query.
      while($video = $result->fetch_assoc()):
          $liked = ($userId > 0) ? in_array($video['id'], $userLikes) : false;
          $favorited = ($userId > 0) ? in_array($video['id'], $userFavorites) : false;
          $profileImage = htmlspecialchars(convertLink(getProfileImage($video['profile_image'])));
          $posterImage = !empty($video['thumbnail']) ? htmlspecialchars(convertLink($video['thumbnail'])) : 'default-thumbnail.jpg';
          $isPhotoPost = empty($video['video_path']) && !empty($video['images']);
          $postImages = (!empty($video['images'])) ? json_decode($video['images'], true) : [];
    ?>
    <div class="video-item" data-video-id="<?php echo $video['id']; ?>"
         data-userid="<?php echo $video['user_id']; ?>"
         data-username="<?php echo htmlspecialchars($video['display_name']); ?>"
         data-title="<?php echo htmlspecialchars($video['title']); ?>"
         data-verified="<?php echo $video['verified']; ?>"
         data-isphotopost="<?php echo $isPhotoPost ? '1' : '0'; ?>">
      <?php if ($isPhotoPost && !empty($postImages) && is_array($postImages)): ?>
        <div class="photo-badge" style="position:absolute; top:5px; right:5px; background:#ff3b30; padding:2px 6px; border-radius:3px; font-size:12px;">Photos</div>
        <?php if (count($postImages) > 1): ?>
          <div class="image-slider">
            <div class="slides">
              <?php foreach ($postImages as $imgPath): 
                        $imgPath = htmlspecialchars(convertLink($imgPath)); ?>
                <img src="<?php echo $imgPath; ?>" alt="Post Image" class="slide">
              <?php endforeach; ?>
            </div>
            <div class="slide-counter">1/<?php echo count($postImages); ?></div>
          </div>
        <?php else: 
                  $singleImage = htmlspecialchars(convertLink($postImages[0])); ?>
          <div class="single-image">
            <img src="<?php echo $singleImage; ?>" alt="Post Image">
          </div>
        <?php endif; ?>
        <?php if (!empty($video['music_url'])): ?>
          <audio class="bg-music" src="<?php echo htmlspecialchars(convertLink($video['music_url'])); ?>" preload="auto" loop></audio>
        <?php endif; ?>
      <?php else: ?>
      <video preload="auto" src="<?php echo htmlspecialchars(convertLink($video['video_path'])); ?>" poster="<?php echo $posterImage; ?>" loop playsinline muted>
        Your browser does not support the video tag.
      </video>
<div class="video-progress">
  <div class="video-progress-fill"></div>
  <div class="seek-handle"></div>
</div>
<!-- Time display (static at bottom center) -->
<div class="seek-time-display"></div>
      <?php endif; ?>
      <div class="sound-toggle" onclick="toggleSound(event, this)">
        <img src="https://img.icons8.com/ios-filled/50/ffffff/mute.png" alt="Sound">
      </div>
      <div class="right-icons">
        <div class="profile-pic">
          <a href="<?php echo ($userId > 0) ? "user.php?user_id=".$video['user_id'] : "login.php"; ?>">
            <img src="<?php echo $profileImage; ?>" alt="Profile">
          </a>
          <?php if($userId > 0 && $video['user_id'] != $userId):
                   $followCheckRes = $conn->query("SELECT COUNT(*) as cnt FROM follows WHERE follower_id = $userId AND following_id = " . $video['user_id']);
                   $isFollowing = ($followCheckRes && $followCheckRes->fetch_assoc()['cnt'] > 0);
                   if(!$isFollowing): ?>
                     <div class="follow-button" onclick="toggleFollow(<?php echo $video['user_id']; ?>, this)">+</div>
          <?php endif; endif; ?>
        </div>
        <div class="icon-button like-button <?php echo $liked ? 'red' : 'white'; ?>" 
             data-video-id="<?php echo $video['id']; ?>" 
             onclick="toggleLike(this)">
          <img src="<?php echo $liked ? 'https://img.icons8.com/fluency/48/ff0000/like.png' : 'https://img.icons8.com/ios-filled/50/ffffff/like.png'; ?>" alt="Like">
          <span class="like-count"><?php echo $video['like_count']; ?></span>
        </div>
        <div class="icon-button comment-button" onclick="openComments(<?php echo $video['id']; ?>)">
          <img src="https://img.icons8.com/ios-filled/50/ffffff/speech-bubble.png" alt="Comments">
          <span><?php echo $video['comment_count']; ?></span>
        </div>
        <div class="icon-button favorite-button" 
             data-video-id="<?php echo $video['id']; ?>" 
             onclick="toggleFavorite(this)">
          <img src="<?php echo $favorited ? 'https://img.icons8.com/ios-filled/50/ffcc00/bookmark-ribbon.png' : 'https://img.icons8.com/ios/50/ffffff/bookmark-ribbon--v1.png'; ?>" alt="Favorite">
          <span class="favorite-count"><?php echo $video['favorite_count']; ?></span>
        </div>
        <div class="icon-button share-button" 
             onclick="shareVideo('<?php echo $video['id']; ?>', <?php echo ($video['user_id'] == $userId ? 'true' : 'false'); ?>)">
          <img src="share.png" alt="Share">
          <span>Share</span>
        </div>
      </div>
    </div>
    <?php endwhile; ?>
    <div id="loadMoreSentinel"></div>
  </div>
<?php else: ?>
  <div class="feed-container">
    <p style="text-align:center; padding:20px; color:red;">Your account is banned. No videos to display.</p>
  </div>
<?php endif; ?>

<script src="/javascript/truncateDescription.js"></script>
<script src="/javascript/toggle.js"></script>
<script src="/javascript/updateViewCount.js"></script>
<script src="/javascript/opencomments.js"></script>
<script>
// Global variables
let currentPage = <?php echo $page; ?>;
const feedContainer = document.getElementById('feedContainer');
const sentinel = document.getElementById('loadMoreSentinel');

// Global observer for video items
let videoObserver;
function initializeVideoObserver() {
  const options = { threshold: 0.5 };
  videoObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      const item = entry.target;
      const vidId = item.getAttribute('data-video-id');
      if (entry.isIntersecting) {
        const videoEl = item.querySelector('video');
        if (videoEl) {
          videoEl.muted = !globalSound;
          videoEl.play().catch(()=>{});
          const soundToggle = item.querySelector('.sound-toggle');
          if (soundToggle) {
            soundToggle.querySelector('img').src = globalSound 
              ? "https://img.icons8.com/ios-filled/50/ffffff/medium-volume.png" 
              : "https://img.icons8.com/ios-filled/50/ffffff/mute.png";
          }
        } else {
          const audioEl = item.querySelector('audio.bg-music');
          if (audioEl) {
            audioEl.muted = !globalSound;
            audioEl.play().catch(()=>{});
          }
        }
        updateBottomOverlay(item);
        if (vidId && !viewedVideos.includes(vidId)) {
          viewedVideos.push(vidId);
          updateViewCount(vidId);
        }
      } else {
        const videoEl = item.querySelector('video');
        if (videoEl) videoEl.pause();
        else {
          const audioEl = item.querySelector('audio.bg-music');
          if (audioEl) audioEl.pause();
        }
      }
    });
  }, options);
  document.querySelectorAll('.video-item').forEach(item => videoObserver.observe(item));
}
initializeVideoObserver();

let isLoading = false;
function loadMoreVideos() {
  if (isLoading) return;
  isLoading = true;
  currentPage++;
  // NEW: Gather existing video IDs to exclude duplicates
  const existingVideoIds = Array.from(document.querySelectorAll('.video-item'))
                              .map(item => item.getAttribute('data-video-id'))
                              .join(',');
  fetch(window.location.pathname + "?ajax=1&page=" + currentPage + "&exclude=" + existingVideoIds)
    .then(response => response.json())
    .then(data => {
      if (data.length > 0) {
        data.forEach(html => {
          const temp = document.createElement('div');
          temp.innerHTML = html;
          const newItem = temp.firstElementChild;
          feedContainer.appendChild(newItem);
          
          videoObserver.observe(newItem);
          const slider = newItem.querySelector('.image-slider');
          if (slider) {
            enableImageSliderSwipe(slider);
          }
          initializeVideoSeekbar(newItem);
          initializeVideoPauseToggle(newItem);
        });
        console.log("Batch loaded on page", currentPage);
        setTimeout(checkThirdToLast, 100);
      } else {
        console.log("No more videos to load.");
        feedContainer.removeEventListener('scroll', onFeedScroll);
      }
      isLoading = false;
    })
    .catch(err => {
      console.error("Error loading videos:", err);
      isLoading = false;
    });
}

function checkThirdToLast() {
  const items = feedContainer.querySelectorAll('.video-item');
  if (items.length < 4) {
    loadMoreVideos();
    return;
  }
  const thirdLast = items[items.length - 4];
  const thirdLastRect = thirdLast.getBoundingClientRect();
  const containerRect = feedContainer.getBoundingClientRect();
  
  if (thirdLastRect.top < containerRect.bottom && thirdLastRect.bottom > containerRect.top) {
    loadMoreVideos();
  }
}

function onFeedScroll() {
  checkThirdToLast();
}
document.addEventListener('DOMContentLoaded', function() {
  feedContainer.addEventListener('scroll', onFeedScroll);
});

function initializeVideoSeekbar(item) {
  const videoEl = item.querySelector('video');
  const progressBar = item.querySelector('.video-progress');
  if (!videoEl || !progressBar) return;

  // Create or reuse the fill element
  let fillEl = progressBar.querySelector('.video-progress-fill');
  if (!fillEl) {
    fillEl = document.createElement('div');
    fillEl.className = 'video-progress-fill';
    progressBar.appendChild(fillEl);
  }
  
  // Create or reuse the circular handle element
  let handleEl = progressBar.querySelector('.seek-handle');
  if (!handleEl) {
    handleEl = document.createElement('div');
    handleEl.className = 'seek-handle';
    progressBar.appendChild(handleEl);
  }
  
  // Create or reuse the static time display element (fixed at center)
  let timeDisplay = item.querySelector('.seek-time-display');
  if (!timeDisplay) {
    timeDisplay = document.createElement('div');
    timeDisplay.className = 'seek-time-display';
    item.appendChild(timeDisplay);
  }
  
  // On metadata load, reset fill and handle
  videoEl.addEventListener('loadedmetadata', () => {
    fillEl.style.width = "0%";
    handleEl.style.left = "0%";
  });
  
  // Update fill and handle on timeupdate
  videoEl.addEventListener('timeupdate', () => {
    if (videoEl.duration) {
      const pct = (videoEl.currentTime / videoEl.duration) * 100;
      fillEl.style.width = pct + '%';
      handleEl.style.left = pct + '%';
    }
  });
  
  // When video is buffering, add the loading class to the progress bar to show the wave effect
  videoEl.addEventListener('waiting', () => {
    progressBar.classList.add('loading');
  });
  
  // When video resumes playing, remove the loading class
  videoEl.addEventListener('playing', () => {
    progressBar.classList.remove('loading');
  });
  
  // (Your existing code for dragging / updating during mouse and touch events)
  let dragging = false;
  
  function updateDrag(clientX) {
    const rect = progressBar.getBoundingClientRect();
    let offset = clientX - rect.left;
    offset = Math.max(0, Math.min(offset, rect.width));
    const ratio = offset / rect.width;
    const newTime = ratio * videoEl.duration;
    fillEl.style.width = (ratio * 100) + '%';
    handleEl.style.left = (ratio * 100) + '%';
    timeDisplay.textContent = formatTime(newTime) + " / " + formatTime(videoEl.duration);
  }
  
  progressBar.addEventListener('mousedown', (e) => {
    if (videoEl.duration) {
      dragging = true;
      progressBar.classList.add('dragging');
      handleEl.style.display = 'block';
      timeDisplay.style.display = 'block';
      updateDrag(e.clientX);
    }
  });
  
  document.addEventListener('mousemove', (e) => {
    if (dragging) {
      updateDrag(e.clientX);
    }
  });
  
  document.addEventListener('mouseup', (e) => {
    if (dragging) {
      dragging = false;
      progressBar.classList.remove('dragging');
      handleEl.style.display = 'none';
      timeDisplay.style.display = 'none';
      const rect = progressBar.getBoundingClientRect();
      let offset = e.clientX - rect.left;
      offset = Math.max(0, Math.min(offset, rect.width));
      const ratio = offset / rect.width;
      videoEl.currentTime = ratio * videoEl.duration;
    }
  });
  
  // Touch events similar to mouse events...
  progressBar.addEventListener('touchstart', (e) => {
    if (videoEl.duration) {
      dragging = true;
      progressBar.classList.add('dragging');
      handleEl.style.display = 'block';
      timeDisplay.style.display = 'block';
      updateDrag(e.touches[0].clientX);
    }
  }, { passive: true });
  
  document.addEventListener('touchmove', (e) => {
    if (dragging) {
      updateDrag(e.touches[0].clientX);
    }
  }, { passive: true });
  
  document.addEventListener('touchend', (e) => {
    if (dragging) {
      dragging = false;
      progressBar.classList.remove('dragging');
      handleEl.style.display = 'none';
      timeDisplay.style.display = 'none';
      const rect = progressBar.getBoundingClientRect();
      let offset = e.changedTouches[0].clientX - rect.left;
      offset = Math.max(0, Math.min(offset, rect.width));
      const ratio = offset / rect.width;
      videoEl.currentTime = ratio * videoEl.duration;
    }
  });
  
  // Helper function to format time (mm:ss)
  function formatTime(sec) {
    if (!sec || isNaN(sec)) return "00:00";
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return String(m).padStart(2, '0') + ":" + String(s).padStart(2, '0');
  }
}



// DOMContentLoaded block to initialize default video items:
document.addEventListener('DOMContentLoaded', function(){
  const defaultVideoItems = document.querySelectorAll('.video-item');
  defaultVideoItems.forEach(item => {
    initializeVideoSeekbar(item);
  });
  
  // Force auto-play the very first video (if browser permits)
  const firstItem = defaultVideoItems[0];
  if (firstItem) {
    const firstVideo = firstItem.querySelector('video');
    if (firstVideo) {
      firstVideo.play().then(() => {
        const pauseOverlay = firstItem.querySelector('.pause-overlay');
        if (pauseOverlay) pauseOverlay.style.display = 'none';
      }).catch(err => {
        console.error("Auto-play failed:", err);
        const pauseOverlay = firstItem.querySelector('.pause-overlay');
        if (pauseOverlay) pauseOverlay.style.display = 'block';
      });
    }
  }
});

function initializeVideoPauseToggle(item) {
  const videoEl = item.querySelector('video');
  if (!videoEl) return;
  
  let pauseOverlay = item.querySelector('.pause-overlay');
  if (!pauseOverlay) {
    pauseOverlay = document.createElement('div');
    pauseOverlay.className = 'pause-overlay';
    pauseOverlay.innerHTML = '<img src="https://img.icons8.com/ios-filled/50/ffffff/play--v1.png" alt="Play">';
    item.appendChild(pauseOverlay);
  }
  
  // Check if this item is the first video in the feed
  const allItems = document.querySelectorAll('.video-item');
  if (allItems[0] === item) {
    videoEl.play().then(() => {
      pauseOverlay.style.display = 'none';
    }).catch(() => {
      pauseOverlay.style.display = 'block';
    });
  } else {
    pauseOverlay.style.display = videoEl.paused ? 'block' : 'none';
  }
  
  videoEl.addEventListener('play', function() {
    pauseOverlay.style.display = 'none';
  });
  videoEl.addEventListener('pause', function() {
    pauseOverlay.style.display = 'block';
  });
  videoEl.addEventListener('timeupdate', function() {
    if (!videoEl.paused) {
      pauseOverlay.style.display = 'none';
    }
  });
  videoEl.addEventListener('click', function(e) {
    if (videoEl.paused) {
      videoEl.play();
    } else {
      videoEl.pause();
    }
  });
  pauseOverlay.addEventListener('click', function(e) {
    e.stopPropagation();
    videoEl.play();
  });
}


document.addEventListener('DOMContentLoaded', function(){
  // Initialize pause toggle for all default video items
  const videoItems = document.querySelectorAll('.video-item');
  videoItems.forEach(item => {
    initializeVideoPauseToggle(item);
  });

  // Force auto-play the very first video if possible
  const firstVideoItem = videoItems[0];
  if (firstVideoItem) {
    const firstVideo = firstVideoItem.querySelector('video');
    if (firstVideo) {
      firstVideo.play().then(() => {
        const pauseOverlay = firstVideoItem.querySelector('.pause-overlay');
        if (pauseOverlay) pauseOverlay.style.display = 'none';
      }).catch(err => {
        console.error("Auto-play failed:", err);
        const pauseOverlay = firstVideoItem.querySelector('.pause-overlay');
        if (pauseOverlay) pauseOverlay.style.display = 'block';
      });
    }
  }
});



function updateBottomOverlay(item) {
  if (!item) return;
  const username = item.dataset.username || '';
  const uid = item.dataset.userid || '';
  const title = item.dataset.title || '';
  const verified = item.dataset.verified;
  const isPhoto = item.dataset.isphotopost === "1";
  let html = `<div class="username">
                <a href="user.php?user_id=${uid}" style="text-decoration: none; color: var(--text-color); display: inline-flex; align-items: center;">${username}`;
  if (verified === "1") {
    html += `<img src="verified-badge.png" alt="Verified" style="width:16px; height:16px; margin-left:4px;">`;
  }
  if (isPhoto) {
    html += `<img src="https://img.icons8.com/ios-filled/50/ffffff/image.png" alt="Photo" style="width:16px; height:16px; margin-left:4px;">`;
  }
  html += `</a></div>`;
  html += `<div class="title" data-fulltext="${title}">${title.replace(/#(\\w+)/g, '<span class="hashtag">#$1</span>')}</div>`;
  document.getElementById('bottom-overlay').innerHTML = html;
  truncateDescription();
}

function openSearchOverlay() {
  document.getElementById('searchOverlay').style.display = 'block';
  document.getElementById('searchInput').focus();
}

function closeSearchOverlay() {
  document.getElementById('searchOverlay').style.display = 'none';
}

function openChat() {
  document.getElementById('chatOverlay').style.display = 'block';
}

function closeChat() {
  document.getElementById('chatOverlay').style.display = 'none';
}

let searchTimeout = null;
function handleSearchInput() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    const query = document.getElementById('searchInput').value.trim();
    if (!query) {
      document.getElementById('searchResults').innerHTML = '';
      return;
    }
    fetch('search.php?q=' + encodeURIComponent(query))
      .then(res => res.text())
      .then(html => {
        document.getElementById('searchResults').innerHTML = html;
        initializeSearchTabs();
      })
      .catch(err => {
        console.error(err);
        document.getElementById('searchResults').innerHTML = 'Error searching.';
      });
  }, 300);
}

function initializeSearchTabs() {
  var tabVideos = document.getElementById('tab-videos');
  var tabPeople = document.getElementById('tab-people');
  var resultsVideos = document.getElementById('results-videos');
  var resultsPeople = document.getElementById('results-people');
  if(tabVideos && tabPeople && resultsVideos && resultsPeople) {
    tabVideos.addEventListener('click', function(){
      resultsVideos.style.display = 'block';
      resultsPeople.style.display = 'none';
      tabVideos.classList.add('active');
      tabPeople.classList.remove('active');
    });
    tabPeople.addEventListener('click', function(){
      resultsVideos.style.display = 'none';
      resultsPeople.style.display = 'block';
      tabPeople.classList.add('active');
      tabVideos.classList.remove('active');
    });
  }
}

function updateNotificationBadge(){
  fetch('get_notifications.php')
    .then(response => response.json())
    .then(data => {
      const badge = document.getElementById('notifBadge');
      if(data.success && data.count > 0){
        badge.innerText = data.count;
        badge.style.display = 'block';
      } else {
        badge.style.display = 'none';
      }
    })
    .catch(error => { console.error('Error fetching notifications: ', error); });
}

setInterval(updateNotificationBadge, 30000);
updateNotificationBadge();

function openNotificationsOverlay() {
  document.getElementById('notificationsOverlay').style.display = 'block';
  document.getElementById('notificationsFrame').src = "notifications.php";
  fetch('mark_notifications_read.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'user_id=' + <?php echo $userId; ?>
  })
  .then(response => response.json())
  .then(data => {
    if(data.success){
      document.getElementById('notifBadge').style.display = 'none';
    }
  })
  .catch(err => console.error('Error marking notifications read:', err));
}

function closeNotificationsOverlay() {
  document.getElementById('notificationsOverlay').style.display = 'none';
}

window.onclick = function(event) {
  const uploadModal = document.getElementById('uploadFrameModal');
  if(event.target === uploadModal){ closeUploadFrame(); }
};


function shareVideo(videoId) {
  const shareUrl = window.location.origin + '/watch.php?video_id=' + videoId;
  navigator.clipboard.writeText(shareUrl)
    .then(() => { alert('Video link copied: ' + shareUrl); })
    .catch(err => console.error('Could not copy text: ', err));
}

function openUploadFrame() {
  if (!isLoggedIn) {
    alert('Please log in to upload videos.');
    return;
  }
  var uploadFrame = document.getElementById('uploadFrame');
  if (!uploadFrame.src || uploadFrame.src === window.location.href) {
    uploadFrame.src = "uploadmedia.php";
  }
  document.getElementById('uploadFrameModal').style.display = "flex";
}

function closeUploadFrame() {
  document.getElementById('uploadFrameModal').style.display = "none";
}

function toggleFollow(userId, followBtn) {
  if(!isLoggedIn){
    alert('Please log in to follow users.');
    return;
  }
  fetch('follow.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'user_id=' + userId
  })
  .then(response => response.json())
  .then(data => {
    if(data.success){
      if(data.following){
        followBtn.classList.add('follow-pulse');
        setTimeout(() => { followBtn.style.display = 'none'; }, 600);
      } else {
        alert('Unfollowed');
      }
    } else {
      alert('Error toggling follow');
    }
  })
  .catch(err => console.error('Error:', err));
}

function toggleSound(e, btn) {
  e.stopPropagation();
  globalSound = !globalSound;
  document.querySelectorAll('.video-item').forEach(function(item) {
    const videoEl = item.querySelector('video');
    const audioEl = item.querySelector('audio.bg-music');
    if (videoEl) { videoEl.muted = !globalSound; }
    if (audioEl) { audioEl.muted = !globalSound; }
    const soundToggle = item.querySelector('.sound-toggle');
    if (soundToggle) {
      const img = soundToggle.querySelector('img');
      img.src = globalSound 
                ? "https://img.icons8.com/ios-filled/50/ffffff/medium-volume.png" 
                : "https://img.icons8.com/ios-filled/50/ffffff/mute.png";
    }
  });
}

document.addEventListener("contextmenu", function(e){ e.preventDefault(); });
document.onkeydown = function(e) {
  if(e.keyCode === 123 ||
     (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) ||
     (e.ctrlKey && e.keyCode === 85)){
      e.preventDefault();
      return false;
  }
};

function currentSlide(dot, index) {
  const slider = dot.closest('.image-slider');
  const slidesContainer = slider.querySelector('.slides');
  slidesContainer.style.transform = 'translateX(' + (-index * 100) + '%)';
  const dots = slider.querySelectorAll('.dot');
  dots.forEach((d, i) => { d.classList.toggle('active', i === index); });
}

function enableImageSliderSwipe(slider) {
  const slidesContainer = slider.querySelector('.slides');
  let startX = 0;
  let currentIndex = 0;
  const slides = slider.querySelectorAll('img.slide');
  const totalSlides = slides.length;
  slidesContainer.addEventListener('touchstart', function(e){
    startX = e.touches[0].clientX;
  });
  slidesContainer.addEventListener('touchend', function(e){
    const endX = e.changedTouches[0].clientX;
    const diff = endX - startX;
    if (diff < -50 && currentIndex < totalSlides - 1) { currentIndex++; }
    else if (diff > 50 && currentIndex > 0) { currentIndex--; }
    slidesContainer.style.transform = 'translateX(' + (-currentIndex * 100) + '%)';
    const dots = slider.querySelectorAll('.dot');
    dots.forEach((d, i) => { d.classList.toggle('active', i === currentIndex); });
  });
}

  document.addEventListener('DOMContentLoaded', function(){
  const items = document.querySelectorAll('.video-item');
  // Increase threshold so videos load slightly earlier (adjust as needed)
  const observerOptions = { threshold: 0.5 };

  const observerCallback = (entries) => {
    entries.forEach(entry => {
      const videoItem = entry.target;
      const vidId = videoItem.getAttribute('data-video-id');
      
      if (entry.isIntersecting) {
        // If a <video> element exists, handle its playback
        const videoEl = videoItem.querySelector('video');
        if (videoEl) {
          videoEl.muted = !globalSound;
          videoEl.play().catch(()=>{});
          // Update the sound toggle icon if available
          const soundToggle = videoItem.querySelector('.sound-toggle');
          if (soundToggle) {
            const img = soundToggle.querySelector('img');
            img.src = globalSound 
                      ? "https://img.icons8.com/ios-filled/50/ffffff/medium-volume.png" 
                      : "https://img.icons8.com/ios-filled/50/ffffff/mute.png";
          }
        } else {
          // For photo posts with background music
          const audioEl = videoItem.querySelector('audio.bg-music');
          if (audioEl) {
            audioEl.muted = !globalSound;
            audioEl.play().catch(()=>{});
          }
        }
        // Update bottom overlay if a new video item becomes active
        if (currentActiveItem !== videoItem) {
          currentActiveItem = videoItem;
          updateBottomOverlay(videoItem);
        }
        // Update view count if not already recorded
        if (vidId && !viewedVideos.includes(vidId)) {
          viewedVideos.push(vidId);
          updateViewCount(vidId);
        }
      } else {
        // Pause the video or audio when out of view
        const videoEl = videoItem.querySelector('video');
        if (videoEl) {
          videoEl.pause();
        } else {
          const audioEl = videoItem.querySelector('audio.bg-music');
          if (audioEl) {
            audioEl.pause();
          }
        }
      }
    });
  };

  const observer = new IntersectionObserver(observerCallback, observerOptions);
  items.forEach(item => observer.observe(item));

  // Enable swipe functionality on each image slider as before.
  document.querySelectorAll('.image-slider').forEach(slider => {
    enableImageSliderSwipe(slider);
  });
});

    

// Global variables to track the video being shared.
var currentShareVideoId = null;
var currentShareVideoIsOwner = false;
// Use this shareVideo() definition – it accepts two parameters and opens the modal.
function shareVideo(videoId, isOwner) {
  currentShareVideoId = videoId;
  currentShareVideoIsOwner = isOwner;
  
  // Show owner-only options if the current user is the owner.
  var ownerOptions = document.getElementById('ownerOptions');
  if (isOwner) {
    ownerOptions.style.display = 'block';
  } else {
    ownerOptions.style.display = 'none';
  }
  openShareModal();
}

function openShareModal() {
  const shareModal = document.getElementById('shareModal');
  shareModal.style.display = 'flex'; // Use flex to match CSS layout
  // Disable background scrolling while modal is open
  document.body.style.overflow = 'hidden';
  // Add a click listener to the overlay (the shareModal element)
  shareModal.addEventListener('click', function(e) {
    if (e.target === shareModal) {
      closeShareModal();
    }
  });
}

function closeShareModal() {
  const shareModal = document.getElementById('shareModal');
  shareModal.style.display = 'none';
  // Restore background scrolling
  document.body.style.overflow = '';
}
// Copy Link option
function copyLink(videoId) {
  var shareUrl = window.location.origin + '/watch.php?video_id=' + videoId;
  navigator.clipboard.writeText(shareUrl)
    .then(() => {
      alert('Video link copied: ' + shareUrl);
      closeShareModal();
    })
    .catch(err => {
      console.error('Error copying link: ', err);
      alert('Failed to copy link.');
    });
}

// Download Video option: creates an anchor that downloads from your download endpoint.
function downloadVideo(videoId) {
  var downloadUrl = window.location.origin + '/download_video.php?video_id=' + videoId;
  // Create an invisible anchor element to trigger the download.
  var a = document.createElement('a');
  a.href = downloadUrl;
  a.download = "video_" + videoId + ".mp4";
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  closeShareModal();
}

// Owner-only: Delete Video option.
function deleteVideo(videoId) {
  if (!confirm("Are you sure you want to delete this video? This action cannot be undone.")) {
    return;
  }
  fetch('delete_video.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'video_id=' + videoId
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert('Video deleted successfully.');
      var videoItem = document.querySelector('.video-item[data-video-id="' + videoId + '"]');
      if (videoItem) {
        videoItem.parentNode.removeChild(videoItem);
      }
    } else {
      alert('Error: ' + data.error);
    }
    closeShareModal();
  })
  .catch(err => {
    console.error('Error deleting video:', err);
    alert('Error deleting video.');
    closeShareModal();
  });
}

// Owner-only: Edit Title option.
function editTitle(videoId) {
  // Find the video item and retrieve its current title
  var videoItem = document.querySelector('.video-item[data-video-id="' + videoId + '"]');
  var currentTitle = videoItem ? videoItem.getAttribute('data-title') : "";
  
  // Prompt the user with the current title pre-filled
  var newTitle = prompt("Edit title:", currentTitle);
  if (newTitle === null) return; // User cancelled
  if (!newTitle) return; // Empty title, do nothing
  
  fetch('edit_title.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'video_id=' + videoId + '&new_title=' + encodeURIComponent(newTitle)
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert('Title updated successfully.');
      if (videoItem) {
        // Update the data-title attribute
        videoItem.setAttribute('data-title', newTitle);
        // Optionally update the bottom overlay title if it's visible
        var bottomOverlay = document.getElementById('bottom-overlay');
        if (bottomOverlay) {
          // Assume the title is within an element with class "title"
          var titleElem = bottomOverlay.querySelector('.title');
          if (titleElem) {
            // Update the text and also reapply hashtag formatting
            titleElem.innerHTML = newTitle.replace(/#(\w+)/g, '<span class="hashtag">#$1</span>');
            // Also update its data-fulltext attribute if needed
            titleElem.setAttribute('data-fulltext', newTitle);
          }
        }
      }
    } else {
      alert('Error: ' + data.error);
    }
    closeShareModal();
  })
  .catch(err => {
    console.error('Error updating title:', err);
    alert('Error updating title.');
    closeShareModal();
  });
}

  // Updated slider swipe function that updates the counter
  function enableImageSliderSwipe(slider) {
    const slidesContainer = slider.querySelector('.slides');
    let startX = 0;
    let currentIndex = 0;
    const slides = slider.querySelectorAll('img.slide');
    const totalSlides = slides.length;
    const counterElem = slider.querySelector('.slide-counter');
    
    // Initialize counter display
    if (counterElem) {
      counterElem.textContent = (currentIndex + 1) + '/' + totalSlides;
    }
    
    slidesContainer.addEventListener('touchstart', function(e) {
      startX = e.touches[0].clientX;
    });
    
    slidesContainer.addEventListener('touchend', function(e) {
      const endX = e.changedTouches[0].clientX;
      const diff = endX - startX;
      if (diff < -50 && currentIndex < totalSlides - 1) {
        currentIndex++;
      } else if (diff > 50 && currentIndex > 0) {
        currentIndex--;
      }
      slidesContainer.style.transform = 'translateX(' + (-currentIndex * 100) + '%)';
      if (counterElem) {
        counterElem.textContent = (currentIndex + 1) + '/' + totalSlides;
      }
    });
  }
 
  document.addEventListener("DOMContentLoaded", function(){
    // For logged-in users, send a heartbeat every 10 seconds
    <?php if (isset($_SESSION['user_id'])): ?>
      setInterval(function(){
        fetch('user.php?action=heartbeat')
          .then(response => response.json())
          .then(data => {
            // (Optional) Handle the response if needed.
            // console.log("Heartbeat sent:", data);
          })
          .catch(err => console.error("Heartbeat error:", err));
      }, 10000); // 10000 ms = 10 seconds
    <?php endif; ?>
  });
  
  document.addEventListener('copy', function(e) {
    e.preventDefault();
  });
  
  document.addEventListener('DOMContentLoaded', function(){
    // Additional DOMContentLoaded functionality can be added here if needed.
  });

</script>

</body>
</html>
