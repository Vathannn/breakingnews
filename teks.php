<?php
$title  = "Never Ending Fun";
$author = "./Vathan";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background:#0d1117;
    color:#fff;
    font-family:Consolas, Monaco, monospace;
    overflow:hidden;
}

.container{
    text-align:center;
}

.title{
    font-size:clamp(3rem,8vw,7rem);
    font-weight:800;
    letter-spacing:5px;
    text-transform:uppercase;
    text-shadow:
        0 0 10px rgba(255,255,255,.3),
        0 0 30px rgba(0,255,255,.3);
    animation:float 4s ease-in-out infinite;
}

.author{
    margin-top:25px;
    font-size:1.2rem;
    color:#00ffff;
    text-shadow:0 0 10px #00ffff;
}

.typing{
    display:inline-block;
    overflow:hidden;
    white-space:nowrap;
    border-right:2px solid #00ffff;
    width:0;
    animation:
        typing 3s steps(8,end) infinite,
        blink .8s infinite;
}

@keyframes typing{
    0%{width:0;}
    40%{width:8ch;}
    80%{width:8ch;}
    100%{width:0;}
}

@keyframes blink{
    50%{
        border-color:transparent;
    }
}

@keyframes float{
    0%,100%{
        transform:translateY(0);
    }
    50%{
        transform:translateY(-10px);
    }
}
</style>
</head>
<body>

<div class="container">
    <div class="title"><?= htmlspecialchars($title) ?></div>

    <div class="author">
        <span class="typing"><?= htmlspecialchars($author) ?></span>
    </div>
</div>

</body>
</html>
