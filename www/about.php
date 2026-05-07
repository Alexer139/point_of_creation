<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';

layout_start('О проекте', ['body_class' => 'about-page']);
?>

<?php $navbar_active = 'about';
require __DIR__ . '/templates/navbar.php'; ?>

<div class="about-hero">
  <div class="about-hero__eyebrow"><?= icon('sparkles', '', 14) ?> Инструмент для 2026</div>
  <h1 class="about-hero__title">Point of <em>Creation</em></h1>
  <p class="about-hero__sub">
    Личное и командное пространство для фокуса, продуктивности и осмысленной работы
    в эпоху информационного перегруза.
  </p>
</div>

<div class="about-content">

  <!-- МИССИЯ -->
  <section class="about-section">
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

  <!-- АРХИТЕКТУРА -->
  <section class="about-section">
    <h2 class="about-section__title">Иерархическая структура пространства</h2>
    <div class="about-section__body">
      <p>
        Приложение построено на трёхуровневой модели данных, которая даёт полную свободу
        в организации любого объёма информации — от личного дневника до командного рабочего пространства.
      </p>
    </div>
    <div class="about-hierarchy">
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--1"><?= icon('layout-dashboard', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Дашборды</div>
          <div class="about-hier-item__desc">Независимые пространства для разных контекстов — «Работа», «Личное»,
            «Проект». Переключайтесь между ними в один клик.</div>
        </div>
      </div>
      <div class="about-hier-arrow"><?= icon('chevron-down', '', 16) ?></div>
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--2"><?= icon('file', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Страницы</div>
          <div class="about-hier-item__desc">Внутри каждого дашборда — неограниченное количество страниц-вкладок. Каждая
            страница — чистый холст для виджетов.</div>
        </div>
      </div>
      <div class="about-hier-arrow"><?= icon('chevron-down', '', 16) ?></div>
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--3"><?= icon('layout-grid', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Виджеты</div>
          <div class="about-hier-item__desc">Строительные блоки пространства: заметки, задачи, метрики, графики, таймеры
            и многое другое. Свободно расставляйте и масштабируйте.</div>
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- ФУНКЦИИ -->
  <section class="about-section">
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
        <div class="about-feature__icon"><?= icon('layout-dashboard', '', 22) ?></div>
        <div class="about-feature__title">Мульти-дашборды</div>
        <div class="about-feature__desc">Несколько независимых пространств для разных сфер жизни и работы.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('file-plus', '', 22) ?></div>
        <div class="about-feature__title">Многостраничность</div>
        <div class="about-feature__desc">Создавайте любое количество страниц внутри дашборда — переключение через
          вкладки.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('users', '', 22) ?></div>
        <div class="about-feature__title">Совместный доступ</div>
        <div class="about-feature__desc">Приглашайте коллег по email. Гибкие роли: наблюдатель или редактор.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('hash', '', 22) ?></div>
        <div class="about-feature__title">Числовые показатели</div>
        <div class="about-feature__desc">Отслеживайте любые метрики с трендами и динамикой изменений.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('list-checks', '', 22) ?></div>
        <div class="about-feature__title">Списки и задачи</div>
        <div class="about-feature__desc">Чеклисты с прогрессом — никогда не теряйте нить ключевых задач.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('target', '', 22) ?></div>
        <div class="about-feature__title">Цели с прогрессом</div>
        <div class="about-feature__desc">Визуальная полоска прогресса держит вас в фокусе на пути к результату.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('timer', '', 22) ?></div>
        <div class="about-feature__title">Таймер и Помодоро</div>
        <div class="about-feature__desc">Обратный отсчёт и секундомер для управления рабочими сессиями.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('calendar', '', 22) ?></div>
        <div class="about-feature__title">Календарь с заметками</div>
        <div class="about-feature__desc">Вычёркивайте дни, оставляйте быстрые заметки прямо на дате.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('bar-chart-2', '', 22) ?></div>
        <div class="about-feature__title">Графики и таблицы</div>
        <div class="about-feature__desc">Визуализируйте данные и ведите структурированные записи прямо на дашборде.
        </div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('moon', '', 22) ?></div>
        <div class="about-feature__title">Тёмная тема</div>
        <div class="about-feature__desc">Комфортная работа в любое время суток с мгновенным переключением.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('move', '', 22) ?></div>
        <div class="about-feature__title">Drag &amp; Drop + Resize</div>
        <div class="about-feature__desc">Полная свобода в организации виджетов — перетаскивайте и масштабируйте.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('shield', '', 22) ?></div>
        <div class="about-feature__title">Контроль доступа</div>
        <div class="about-feature__desc">Владелец управляет ролями. Наблюдатели не могут изменить ни байта ваших данных.
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- КОРПОРАТИВНЫЙ РЕЖИМ -->
  <section class="about-section">
    <h2 class="about-section__title">Корпоративный режим</h2>
    <div class="about-section__body">
      <p>
        Любой дашборд можно открыть для других пользователей системы. Владелец приглашает
        коллегу по email и назначает ему одну из двух ролей:
      </p>
    </div>
    <div class="about-roles">
      <div class="about-role about-role--viewer">
        <div class="about-role__head">
          <?= icon('eye', '', 18) ?>
          <span class="about-role__name">Наблюдатель</span>
          <span class="about-role__badge">viewer</span>
        </div>
        <ul class="about-role__list">
          <li><?= icon('check', '', 13) ?> Просмотр всех страниц и виджетов</li>
          <li><?= icon('check', '', 13) ?> Переключение между страницами</li>
          <li><?= icon('x', '', 13) ?> Создание и удаление виджетов</li>
          <li><?= icon('x', '', 13) ?> Редактирование содержимого</li>
          <li><?= icon('x', '', 13) ?> Управление страницами</li>
        </ul>
      </div>
      <div class="about-role about-role--editor">
        <div class="about-role__head">
          <?= icon('edit-3', '', 18) ?>
          <span class="about-role__name">Редактор</span>
          <span class="about-role__badge about-role__badge--editor">editor</span>
        </div>
        <ul class="about-role__list">
          <li><?= icon('check', '', 13) ?> Все права наблюдателя</li>
          <li><?= icon('check', '', 13) ?> Создание и редактирование виджетов</li>
          <li><?= icon('check', '', 13) ?> Управление страницами (создание, удаление)</li>
          <li><?= icon('x', '', 13) ?> Удаление дашборда</li>
          <li><?= icon('x', '', 13) ?> Управление участниками</li>
        </ul>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- БЕЗОПАСНОСТЬ -->
  <section class="about-section">
    <h2 class="about-section__title">Ваши данные — только ваши</h2>
    <div class="about-security">
      <div>
        <h3 class="about-security__title">Надёжность и приватность</h3>
        <div class="about-security__body">
          <p>
            Point of Creation хранит все данные в <strong>MySQL</strong> на вашем собственном сервере.
            Никаких сторонних облаков, аналитических сервисов или трекеров. Всё остаётся
            там, куда вы установили приложение — и нигде больше.
          </p>
          <p style="margin-top:.75rem">
            Пароли хэшируются с помощью современного алгоритма <code>bcrypt</code>.
            Все запросы защищены CSRF-токенами. Сессии используют httpOnly-куки.
            Доступ к каждому виджету и странице проверяется на уровне сервера —
            наблюдатель физически не может отправить запрос на редактирование.
          </p>
          <p style="margin-top:.75rem">
            Целостность данных обеспечена каскадными внешними ключами: при удалении
            дашборда автоматически удаляются все его страницы и виджеты.
          </p>
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- ФИЛОСОФИЯ -->
  <section class="about-section">
    <h2 class="about-section__title">Меньше — значит больше</h2>
    <div class="about-section__body">
      <p>
        Мы намеренно отказались от десятков «умных» функций. Нет ИИ-помощников,
        которые пишут за вас. Нет интеграций с десятками сервисов, каждая из которых
        требует подписки. Нет геймификации, которая превращает продуктивность в игру.
      </p>
      <p>
        Есть только чистый инструмент, который помогает думать, планировать
        и делать — в одиночку или вместе с командой. Point of Creation — это точка,
        в которой рождается то, что важно именно вам.
      </p>
    </div>
  </section>

</div>

<footer class="about-footer">
  <div class="about-footer__inner">
    <div>
      <div class="about-footer__brand">
        <div class="about-footer__logo"><?= icon('sparkles', '', 16) ?></div>
        <span class="about-footer__name">Point of <em>Creation</em></span>
      </div>
      <div class="about-footer__copy">
        © 2026 Point of Creation — личное и командное пространство для глубокой работы.
      </div>
    </div>
    <nav class="about-footer__links">
      <?php if (is_logged_in()): ?>
        <a href="/" class="about-footer__link">Дашборд</a>
        <a href="/settings.php" class="about-footer__link">Настройки</a>
      <?php else: ?>
        <a href="/login.php" class="about-footer__link">Войти</a>
        <a href="/register.php" class="about-footer__link">Регистрация</a>
      <?php endif; ?>
    </nav>
  </div>
</footer>

<style>
  /* Иерархия */
  .about-hierarchy {
    display: flex;
    flex-direction: column;
    gap: 0;
    max-width: 560px;
    margin-top: 1.5rem;
  }

  .about-hier-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface-2);
  }

  .about-hier-item__icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .about-hier-item__icon--1 {
    background: rgba(245, 158, 11, .15);
    color: var(--amber);
  }

  .about-hier-item__icon--2 {
    background: rgba(99, 102, 241, .12);
    color: #6366f1;
  }

  .about-hier-item__icon--3 {
    background: rgba(16, 185, 129, .12);
    color: #10b981;
  }

  .about-hier-item__title {
    font-weight: 700;
    color: var(--text-1);
    margin-bottom: .25rem;
  }

  .about-hier-item__desc {
    font-size: .85rem;
    color: var(--text-2);
    line-height: 1.55;
  }

  .about-hier-arrow {
    display: flex;
    justify-content: center;
    padding: .25rem 0;
    color: var(--text-3);
  }

  /* Роли */
  .about-roles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
  }

  .about-role {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    background: var(--surface-2);
  }

  .about-role--editor {
    border-color: rgba(99, 102, 241, .35);
  }

  .about-role__head {
    display: flex;
    align-items: center;
    gap: .6rem;
    margin-bottom: 1rem;
    font-weight: 700;
    color: var(--text-1);
  }

  .about-role__badge {
    margin-left: auto;
    font-size: .72rem;
    padding: 2px 8px;
    border-radius: 20px;
    background: var(--surface-3);
    color: var(--text-3);
    font-weight: 600;
    font-family: monospace;
  }

  .about-role__badge--editor {
    background: rgba(99, 102, 241, .15);
    color: #6366f1;
  }

  .about-role__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    font-size: .84rem;
    color: var(--text-2);
  }

  .about-role__list li {
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  .about-role__list li svg {
    flex-shrink: 0;
  }
</style>



<?php layout_end(); ?>