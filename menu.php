<?php
// 현재 실행 중인 파일명을 가져옵니다 (예: /height.php -> height.php)
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav id="nav-bar">
    <div class="nav-brand">ROAD TOOL</div>
    <ul class="nav-menu">
        <li class="nav-item <?if($current_page=='height.php') echo 'active';?>"><a href="./height.php">높이맵</a></li>
        <li class="nav-item <?if($current_page=='bitmask_view.php') echo 'active';?>"><a href="./bitmask_view.php">비트마스크 보기</a></li>
    </ul>
    <div class="nav-footer">
        v1.0.2 Stable
    </div>
</nav>

<style>
    #nav-bar {
        width: 220px;
        background: #000;
        border-right: 1px solid #2a2a2a;
        display: flex;
        flex-direction: column;
        padding: 0;
    }
    .nav-brand {
        padding: 30px 25px;
        font-weight: 900;
        font-size: 1.2rem;
        color: #ff4444;
        letter-spacing: 2px;
    }
    .nav-menu {
        list-style: none;
        padding: 0;
        margin: 0;
        flex: 1;
    }
    .nav-item a {
        display: block;
        padding: 15px 25px;
        color: #888;
        text-decoration: none;
        font-size: 0.9rem;
        transition: 0.2s;
        border-left: 3px solid transparent;
    }
    .nav-item:hover a {
        color: #fff;
        background: #111;
    }
    .nav-item.active a {
        color: #fff;
        background: #1a1a1a;
        border-left: 3px solid #ff4444;
    }
    .nav-footer {
        padding: 20px 25px;
        font-size: 0.7rem;
        color: #444;
    }
</style>