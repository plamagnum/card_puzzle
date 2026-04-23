(() => {
  const root = document.documentElement;
  const toggle = document.getElementById('themeToggle');
  const storedTheme = localStorage.getItem('card-puzzle-theme');

  if (storedTheme) {
    root.dataset.theme = storedTheme;
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = nextTheme;
      localStorage.setItem('card-puzzle-theme', nextTheme);
    });
  }

  const app = document.getElementById('puzzleApp');
  if (!app) {
    return;
  }

  const puzzle = JSON.parse(app.dataset.puzzle || '{}');
  const roundId = Number(app.dataset.roundId || 0);
  const csrfToken = app.dataset.csrfToken || '';
  let locked = app.dataset.locked === '1';
  let solvedPieces = 0;
  let pointerPiece = null;
  let startX = 0;
  let startY = 0;
  let originX = 0;
  let originY = 0;

  const statusEl = document.getElementById('gameStatus');
  const board = document.createElement('div');
  board.className = 'puzzle-board';
  const tray = document.createElement('div');
  tray.className = 'puzzle-tray';

  app.append(board, tray);

  const cols = Number(puzzle.cols || 4);
  const rows = Number(puzzle.rows || 3);
  const totalPieces = cols * rows;
  const computeTileSize = () => {
    const maxWidth = Math.min(app.clientWidth || window.innerWidth - 32, 540);
    return Math.max(52, Math.min(72, Math.floor(maxWidth / cols) - 4));
  };
  let tileSize = computeTileSize();
  board.style.setProperty('--cols', String(cols));
  board.style.setProperty('--rows', String(rows));
  board.style.backgroundImage = `url(${puzzle.asset})`;

  const targetRects = [];

  const shuffle = (list) => [...list].sort(() => Math.random() - 0.5);

  const shapeForIndex = (mode, index) => {
    const square = ['inset(0 round 14px)'];
    const mixed = [
      'polygon(0 12%, 84% 0, 100% 80%, 10% 100%)',
      'polygon(12% 0, 100% 12%, 86% 100%, 0 88%)',
      'polygon(0 18%, 78% 0, 100% 58%, 24% 100%)',
      'polygon(18% 0, 100% 24%, 82% 100%, 0 76%)'
    ];
    const irregular = [
      'polygon(0 16%, 70% 0, 100% 26%, 90% 100%, 12% 88%)',
      'polygon(22% 0, 100% 18%, 80% 100%, 0 78%, 8% 20%)',
      'polygon(0 20%, 52% 0, 100% 34%, 82% 100%, 0 92%)',
      'polygon(10% 0, 100% 10%, 100% 82%, 26% 100%, 0 56%)',
      'polygon(0 12%, 88% 0, 100% 72%, 52% 100%, 0 84%)'
    ];

    if (mode === 'square') {
      return square[0];
    }
    if (mode === 'mixed') {
      return mixed[index % mixed.length];
    }
    return irregular[index % irregular.length];
  };

  for (let row = 0; row < rows; row += 1) {
    for (let col = 0; col < cols; col += 1) {
      const slot = document.createElement('div');
      slot.className = 'puzzle-slot';
      slot.style.left = `${col * tileSize}px`;
      slot.style.top = `${row * tileSize}px`;
      slot.style.width = `${tileSize}px`;
      slot.style.height = `${tileSize}px`;
      board.appendChild(slot);
      targetRects.push({ col, row });
    }
  }

  const pieces = shuffle(targetRects).map((target, index) => {
    const piece = document.createElement('div');
    piece.className = 'puzzle-piece';
    piece.dataset.col = String(target.col);
    piece.dataset.row = String(target.row);
    piece.style.width = `${tileSize}px`;
    piece.style.height = `${tileSize}px`;
    piece.style.backgroundImage = `url(${puzzle.asset})`;
    piece.style.backgroundSize = `${cols * tileSize}px ${rows * tileSize}px`;
    piece.style.backgroundPosition = `${-target.col * tileSize}px ${-target.row * tileSize}px`;
    piece.style.clipPath = shapeForIndex(puzzle.shapeMode, index);
    piece.style.touchAction = 'none';
    tray.appendChild(piece);
    return piece;
  });

  const applyPieceDimensions = () => {
    tileSize = computeTileSize();
    board.style.width = `${cols * tileSize}px`;
    board.style.height = `${rows * tileSize}px`;

    Array.from(board.querySelectorAll('.puzzle-slot')).forEach((slot, index) => {
      const col = index % cols;
      const row = Math.floor(index / cols);
      slot.style.left = `${col * tileSize}px`;
      slot.style.top = `${row * tileSize}px`;
      slot.style.width = `${tileSize}px`;
      slot.style.height = `${tileSize}px`;
    });

    pieces.forEach((piece) => {
      const col = Number(piece.dataset.col || 0);
      const row = Number(piece.dataset.row || 0);
      piece.style.width = `${tileSize}px`;
      piece.style.height = `${tileSize}px`;
      piece.style.backgroundSize = `${cols * tileSize}px ${rows * tileSize}px`;
      piece.style.backgroundPosition = `${-col * tileSize}px ${-row * tileSize}px`;
    });
  };

  const layoutTray = () => {
    applyPieceDimensions();
    pieces.forEach((piece, index) => {
      if (piece.dataset.locked === '1') {
        const col = Number(piece.dataset.col || 0);
        const row = Number(piece.dataset.row || 0);
        const boardRect = board.getBoundingClientRect();
        const trayRect = tray.getBoundingClientRect();
        const snapX = boardRect.left - trayRect.left + col * tileSize;
        const snapY = boardRect.top - trayRect.top + row * tileSize;
        piece.style.transform = `translate(${snapX}px, ${snapY}px)`;
        piece.dataset.x = String(snapX);
        piece.dataset.y = String(snapY);
        return;
      }
      const perRow = window.innerWidth < 720 ? 4 : 6;
      const gap = 14;
      const x = (index % perRow) * (tileSize + gap);
      const y = Math.floor(index / perRow) * (tileSize + gap);
      piece.style.transform = `translate(${x}px, ${y}px)`;
      piece.dataset.x = String(x);
      piece.dataset.y = String(y);
    });
    tray.style.height = `${Math.ceil(pieces.length / (window.innerWidth < 720 ? 4 : 6)) * (tileSize + 14)}px`;
  };

  const lockGame = (message) => {
    locked = true;
    app.dataset.locked = '1';
    if (message && statusEl) {
      statusEl.textContent = message;
    }
    pieces.forEach((piece) => {
      piece.classList.add('disabled');
    });
  };

  const submitWin = async () => {
    try {
      const response = await fetch('/?api=complete-round', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ roundId })
      });
      const data = await response.json();
      if (data.ok) {
        lockGame(`Переможець: ${data.winnerName} · ${data.winnerTeam}. Раунд заблоковано до наступного старту.`);
      } else if (data.locked) {
        lockGame(data.message || 'Раунд уже завершено.');
      } else if (statusEl) {
        statusEl.textContent = data.message || 'Не вдалося зберегти результат.';
      }
    } catch (error) {
      if (statusEl) {
        statusEl.textContent = 'Помилка мережі під час збереження результату.';
      }
    }
  };

  const onPointerDown = (event) => {
    if (locked) {
      return;
    }

    const piece = event.currentTarget;
    if (piece.dataset.locked === '1') {
      return;
    }

    pointerPiece = piece;
    piece.setPointerCapture(event.pointerId);
    piece.classList.add('dragging');
    startX = event.clientX;
    startY = event.clientY;
    originX = Number(piece.dataset.x || 0);
    originY = Number(piece.dataset.y || 0);
  };

  const onPointerMove = (event) => {
    if (!pointerPiece) {
      return;
    }

    const nextX = originX + (event.clientX - startX);
    const nextY = originY + (event.clientY - startY);
    pointerPiece.style.transform = `translate(${nextX}px, ${nextY}px)`;
    pointerPiece.dataset.x = String(nextX);
    pointerPiece.dataset.y = String(nextY);
  };

  const onPointerUp = (event) => {
    if (!pointerPiece) {
      return;
    }

    pointerPiece.releasePointerCapture(event.pointerId);
    pointerPiece.classList.remove('dragging');

    const col = Number(pointerPiece.dataset.col || 0);
    const row = Number(pointerPiece.dataset.row || 0);
    const boardRect = board.getBoundingClientRect();
    const targetX = col * tileSize;
    const targetY = row * tileSize;
    const currentX = Number(pointerPiece.dataset.x || 0);
    const currentY = Number(pointerPiece.dataset.y || 0);
    const trayRect = tray.getBoundingClientRect();
    const expectedX = boardRect.left - trayRect.left + targetX;
    const expectedY = boardRect.top - trayRect.top + targetY;
    const nearX = Math.abs(currentX - expectedX) < 28;
    const nearY = Math.abs(currentY - expectedY) < 28;

    if (nearX && nearY) {
      pointerPiece.style.transform = `translate(${expectedX}px, ${expectedY}px)`;
      pointerPiece.dataset.x = String(expectedX);
      pointerPiece.dataset.y = String(expectedY);
      pointerPiece.dataset.locked = '1';
      pointerPiece.classList.add('locked');
      solvedPieces += 1;
      if (solvedPieces === totalPieces) {
        submitWin();
      }
    }

    pointerPiece = null;
  };

  pieces.forEach((piece) => {
    piece.addEventListener('pointerdown', onPointerDown);
    piece.addEventListener('pointermove', onPointerMove);
    piece.addEventListener('pointerup', onPointerUp);
    piece.addEventListener('pointercancel', onPointerUp);
  });

  const pollGameState = async () => {
    if (!roundId || locked) {
      return;
    }

    try {
      const response = await fetch('/?api=game-state', { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!data.ok || !data.round || Number(data.round.id) !== roundId) {
        return;
      }
      if (data.round.winnerName) {
        lockGame(`Переможець: ${data.round.winnerName} · ${data.round.winnerTeam}. Очікуйте старту нового раунду.`);
      }
    } catch (error) {
      // Мовчки пропускаємо короткочасні мережеві помилки під час опитування.
    }
  };

  layoutTray();
  window.addEventListener('resize', layoutTray);
  if (locked) {
    lockGame(statusEl ? statusEl.textContent : 'Раунд завершено.');
  }
  window.setInterval(pollGameState, 4000);
})();
