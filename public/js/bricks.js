// Filigrane : un mur de briques qui se construit rangée par rangée, de bas en haut.
// Il monte à l'ouverture, avec le défilement et doucement avec le temps. Le niveau atteint est
// gardé pendant la visite : la page suivante reprend là où le mur en était.
(function () {
    var wall = document.querySelector('[data-brick-wall]');
    if (!wall) { return; }

    var STORAGE_KEY = 'brick-wall-level';
    var START_LEVEL = 0.4;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var rows = [];
    var laid = 0;
    var builtWidth = 0;

    function remembered() {
        try { return parseFloat(sessionStorage.getItem(STORAGE_KEY)) || 0; } catch (error) { return 0; }
    }

    function remember(level) {
        try { sessionStorage.setItem(STORAGE_KEY, String(level)); } catch (error) { /* navigation privée */ }
    }

    // Le mur occupe toute la fenêtre. Chaque brique déborde un peu sur ses voisines :
    // les recouvrements, plus foncés, donnent l'effet de superposition.
    function build() {
        var width = window.innerWidth;
        var height = window.innerHeight;
        var brickWidth = width < 700 ? 86 : 128;
        var brickHeight = width < 700 ? 30 : 40;
        var overlap = 5;
        var fragment = document.createDocumentFragment();
        var rowCount = Math.ceil(height / brickHeight) + 1;

        wall.textContent = '';
        rows = [];
        laid = 0;
        builtWidth = width;

        for (var row = 0; row < rowCount; row++) {
            var bricks = [];
            var offset = row % 2 ? -brickWidth / 2 : 0;
            var columns = Math.ceil(width / brickWidth) + 2;

            for (var column = 0; column < columns; column++) {
                var brick = document.createElement('i');
                var tone = (row * 7 + column * 13) % 5;
                brick.style.left = (offset + column * brickWidth - overlap) + 'px';
                brick.style.bottom = (row * brickHeight - overlap) + 'px';
                brick.style.width = (brickWidth - 3 + overlap * 2) + 'px';
                brick.style.height = (brickHeight - 3 + overlap) + 'px';
                brick.style.setProperty('--o', [0.07, 0.1, 0.13, 0.09, 0.16][tone]);
                brick.style.transitionDelay = (column * 28) + 'ms';
                bricks.push(brick);
                fragment.appendChild(brick);
            }
            rows.push(bricks);
        }
        wall.appendChild(fragment);
    }

    function layUpTo(count) {
        count = Math.min(count, rows.length);
        var position = 0;

        for (; laid < count; laid++, position++) {
            rows[laid].forEach(function (brick, column) {
                // Les rangées posées d'un coup se suivent, de bas en haut.
                brick.style.transitionDelay = (position * 140 + column * 28) + 'ms';
                brick.classList.add('laid');
            });
        }

        remember(rows.length ? laid / rows.length : 0);
    }

    function scrollLevel() {
        var room = document.documentElement.scrollHeight - window.innerHeight;
        return room > 40 ? Math.min(1, window.scrollY / room) : 0;
    }

    function update() {
        layUpTo(Math.ceil(Math.max(scrollLevel(), laid / (rows.length || 1)) * rows.length));
    }

    function start() {
        build();

        var previous = remembered();
        var level = reduceMotion ? 1 : previous;

        if (level > 0) {
            // Déjà construit plus tôt dans la visite : pas de nouvelle animation pour cette partie.
            wall.classList.add('instant');
            layUpTo(Math.ceil(level * rows.length));
            wall.offsetHeight;
            wall.classList.remove('instant');
        }

        if (!reduceMotion) {
            // Lecture de la mise en page : les briques partent de leur position de départ et l'animation se joue.
            wall.offsetHeight;
            layUpTo(Math.ceil(Math.max(level, START_LEVEL, scrollLevel()) * rows.length));
        }
    }

    start();

    if (!reduceMotion) {
        var pending = false;
        window.addEventListener('scroll', function () {
            if (pending) { return; }
            pending = true;
            window.setTimeout(function () {
                pending = false;
                update();
            }, 60);
        }, { passive: true });

        // Une rangée de plus de temps en temps, tant que la page est affichée.
        var timer = window.setInterval(function () {
            if (document.hidden) { return; }
            if (laid >= rows.length) { window.clearInterval(timer); return; }
            layUpTo(laid + 1);
        }, 4000);
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            // Sur téléphone, la barre d'adresse change la hauteur au défilement : seule la largeur compte.
            if (window.innerWidth === builtWidth) { return; }
            var level = rows.length ? laid / rows.length : 0;
            build();
            wall.classList.add('instant');
            layUpTo(Math.ceil(level * rows.length));
            wall.offsetHeight;
            wall.classList.remove('instant');
        }, 200);
    });
})();
