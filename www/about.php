<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/templates/layout.php';

layout_start('О проекте', ['body_class' => 'about-page']);
?>

<!-- Navbar -->
<nav class="navbar">
  <a href="/" class="logo">
    <div class="logo__mark">✦</div>
    <span class="logo__text">Point of <em>Creation</em></span>
  </a>
  <div class="nav-spacer"></div>
  <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">🌙</button>
  <?php if (is_logged_in()): ?>
    <a href="/" class="btn btn--ghost">← Дашборд</a>
    <a href="/logout.php" class="btn btn--danger">Выйти</a>
  <?php else: ?>
    <a href="/login.php" class="btn btn--warm">Войти</a>
  <?php endif; ?>
</nav>

<!-- Hero -->
<div class="about-hero">
  <div class="about-hero__eyebrow">✦ Инструмент для 2026</div>
  <h1 class="about-hero__title">Point of <em>Creation</em></h1>
  <p class="about-hero__sub">
    Личное пространство для фокуса, продуктивности и осмысленной работы
    в эпоху информационного перегруза.
  </p>
</div>

<!-- Content -->
<div class="about-content">

  <!-- Mission -->
  <section class="about-section">
    <div class="about-section__tag">🎯 Миссия</div>
    <h2 class="about-section__title">Почему это важно именно сейчас</h2>
    <div class="about-section__body">
      <p>
        2026 год — это мир, где внимание стало самым ценным ресурсом. Уведомления,
        бесконечные ленты и постоянная переключаемость контекста разрушают способность
        думать глубоко. Большинство инструментов усугубляют проблему: они слишком сложны,
        навязывают чужие рабочие процессы и конкурируют за ваше внимание.
      </p>
      <p>
        <strong>Point of Creation</strong> создан с единственной целью — вернуть вам
        контроль над собственным рабочим пространством. Никаких алгоритмов, рекламы или
        внешних зависимостей. Только вы, ваши задачи и инструменты, которые работают так,
        как нужно именно вам.
      </p>
    </div>
  </section>

  <hr class="about-divider">

  <!-- Key Features -->
  <section class="about-section">
    <div class="about-section__tag">⚡ Ключевые возможности</div>
    <h2 class="about-section__title">Всё что нужно — в одном месте</h2>
    <div class="about-section__body">
      <p>
        Дашборд собирается из виджетов под ваш конкретный стиль работы.
        Перетаскивайте, изменяйте размер, переключайтесь между светлой и тёмной темой.
        Каждое изменение сохраняется автоматически — никаких кнопок «сохранить».
      </p>
    </div>
    <div class="about-features">
      <div class="about-feature">
        <div class="about-feature__icon">🔢</div>
        <div class="about-feature__title">Числовые показатели</div>
        <div class="about-feature__desc">Отслеживайте любые метрики с трендами и динамикой изменений.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">✅</div>
        <div class="about-feature__title">Списки и задачи</div>
        <div class="about-feature__desc">Чеклисты с прогрессом — никогда не теряйте нить ключевых задач.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">🎯</div>
        <div class="about-feature__title">Цели с прогрессом</div>
        <div class="about-feature__desc">Визуальная полоска прогресса держит вас в фокусе на пути к результату.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">⏱</div>
        <div class="about-feature__title">Таймер и Помодоро</div>
        <div class="about-feature__desc">Обратный отсчёт и секундомер для управления рабочими сессиями.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">📅</div>
        <div class="about-feature__title">Календарь с заметками</div>
        <div class="about-feature__desc">Вычёркивайте дни, оставляйте быстрые заметки прямо на дате.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">📊</div>
        <div class="about-feature__title">Графики и таблицы</div>
        <div class="about-feature__desc">Визуализируйте данные и ведите структурированные записи прямо на дашборде.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">🌙</div>
        <div class="about-feature__title">Тёмная тема</div>
        <div class="about-feature__desc">Комфортная работа в любое время суток с мгновенным переключением.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon">↔️</div>
        <div class="about-feature__title">Drag &amp; Drop + Resize</div>
        <div class="about-feature__desc">Полная свобода в организации виджетов — перетаскивайте и масштабируйте.</div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- Data Security -->
  <section class="about-section">
    <div class="about-section__tag">🔒 Безопасность данных</div>
    <h2 class="about-section__title">Ваши данные — только ваши</h2>
    <div class="about-security">
      <div>
        <h3 class="about-security__title">Приватность по умолчанию</h3>
        <div class="about-security__body">
          <p>
            Point of Creation хранит все данные в локальной SQLite-базе на вашем сервере.
            Никаких сторонних облаков, аналитических сервисов или трекеров. Всё остаётся
            там, куда вы установили приложение — и нигде больше.
          </p>
          <p style="margin-top:.75rem">
            Пароли хэшируются с помощью современного алгоритма <code>bcrypt</code>.
            Все запросы защищены CSRF-токенами. Сессии используют httpOnly-куки с защитой
            от перехвата. Соединения с базой данных настроены с включёнными внешними ключами
            и WAL-режимом для целостности данных.
          </p>
          <p style="margin-top:.75rem">
            Вы в полном контроле: экспортируйте, мигрируйте или удаляйте данные в любой момент.
            Открытый код позволяет убедиться в этом самостоятельно.
          </p>
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- Philosophy -->
  <section class="about-section">
    <div class="about-section__tag">💭 Философия</div>
    <h2 class="about-section__title">Меньше — значит больше</h2>
    <div class="about-section__body">
      <p>
        Мы намеренно отказались от десятков «умных» функций. Нет ИИ-помощников,
        которые пишут за вас. Нет интеграций с десятками сервисов, каждая из которых
        требует подписки. Нет геймификации, которая превращает продуктивность в игру.
      </p>
      <p>
        Есть только чистый инструмент, который помогает думать, планировать
        и делать. Point of Creation — это точка, в которой рождается то, что важно именно вам.
      </p>
    </div>
  </section>

</div><!-- /about-content -->

<!-- Footer -->
<footer class="about-footer">
  <div class="about-footer__inner">
    <div>
      <div class="about-footer__brand">
        <div class="about-footer__logo">✦</div>
        <span class="about-footer__name">Point of <em>Creation</em></span>
      </div>
      <div class="about-footer__copy">
        © 2026 Point of Creation — личное пространство для глубокой работы.
        Сделано с заботой о вашем фокусе.
      </div>
    </div>
    <nav class="about-footer__links">
      
    </nav>
  </div>
</footer>

<script>
function toggleTheme(){var t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('poc-theme',t);var b=document.getElementById('theme-toggle');if(b)b.textContent=t==='dark'?'☀️':'🌙';}
</script>

<?php layout_end(); ?>
