<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';

layout_start('О проекте', ['body_class' => 'about-page']);
?>

<?php $navbar_active = 'about';
require __DIR__ . '/templates/navbar.php'; ?>

<div class="about-hero">
  <div class="about-hero__eyebrow"><?= icon('sparkles', '', 14) ?> Продуктивность · 2026</div>
  <h1 class="about-hero__title">Point of <em>Creation</em></h1>
  <p class="about-hero__sub">
    Личное и командное пространство для фокуса, глубокой работы
    и осмысленного планирования.
  </p>
</div>

<div class="about-content">

  <!-- МИССИЯ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('target', '', 15) ?> Миссия</div>
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
    <div class="about-section__tag"><?= icon('layers', '', 15) ?> Архитектура</div>
    <h2 class="about-section__title">Иерархическая структура пространства</h2>
    <div class="about-section__body">
      <p>
        Приложение построено на трёхуровневой модели — от глобального контекста
        до конкретного блока информации.
      </p>
    </div>
    <div class="about-hierarchy">
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--1"><?= icon('layout-dashboard', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Дашборды</div>
          <div class="about-hier-item__desc">Независимые пространства для разных контекстов — «Работа», «Личное»,
            «Проект». Переключайтесь между ними в один клик прямо из шапки.</div>
        </div>
      </div>
      <div class="about-hier-arrow"><?= icon('chevron-down', '', 16) ?></div>
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--2"><?= icon('file', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Страницы</div>
          <div class="about-hier-item__desc">Внутри каждого дашборда — страницы-вкладки. Каждая страница — отдельный
            чистый холст. Переименовывайте двойным кликом.</div>
        </div>
      </div>
      <div class="about-hier-arrow"><?= icon('chevron-down', '', 16) ?></div>
      <div class="about-hier-item">
        <div class="about-hier-item__icon about-hier-item__icon--3"><?= icon('layout-grid', '', 20) ?></div>
        <div>
          <div class="about-hier-item__title">Виджеты</div>
          <div class="about-hier-item__desc">Строительные блоки: заметки, задачи, метрики, графики, таймеры, таблицы.
            Свободно расставляйте, масштабируйте, перетаскивайте.</div>
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- ФУНКЦИИ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('zap', '', 15) ?> Возможности</div>
    <h2 class="about-section__title">Всё что нужно — в одном месте</h2>
    <div class="about-features">
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('layout-dashboard', '', 22) ?></div>
        <div class="about-feature__title">Мульти-дашборды</div>
        <div class="about-feature__desc">Несколько независимых пространств для разных сфер жизни и работы.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('file-plus', '', 22) ?></div>
        <div class="about-feature__title">Многостраничность</div>
        <div class="about-feature__desc">Любое количество страниц-вкладок внутри дашборда с мгновенным переключением.
        </div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('users', '', 22) ?></div>
        <div class="about-feature__title">Совместный доступ</div>
        <div class="about-feature__desc">Приглашайте коллег по email. Роли viewer и editor с разграничением прав.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('bell', '', 22) ?></div>
        <div class="about-feature__title">Уведомления</div>
        <div class="about-feature__desc">Системные оповещения о приглашениях, смене ролей и изменениях подписки.</div>
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
        <div class="about-feature__desc">Визуализируйте данные и ведите структурированные записи на дашборде.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('move', '', 22) ?></div>
        <div class="about-feature__title">Drag & Drop + Resize</div>
        <div class="about-feature__desc">Полная свобода в организации виджетов — перетаскивайте и масштабируйте.</div>
      </div>
      <div class="about-feature">
        <div class="about-feature__icon"><?= icon('moon', '', 22) ?></div>
        <div class="about-feature__title">Светлая и тёмная тема</div>
        <div class="about-feature__desc">Комфортная работа в любое время суток с мгновенным переключением.</div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- ТАРИФЫ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('zap', '', 15) ?> Тарифы</div>
    <h2 class="about-section__title">Выберите свой уровень</h2>
    <div class="about-section__body">
      <p>Начните бесплатно — платите только когда нужно больше пространства.</p>
    </div>
    <div class="about-plans">

      <div class="about-plan">
        <div class="about-plan__head">
          <span class="about-plan__name">Free</span>
          <span class="about-plan__price">0 ₽</span>
        </div>
        <ul class="about-plan__list">
          <li><?= icon('check', '', 13) ?> 3 дашборда</li>
          <li><?= icon('check', '', 13) ?> 5 страниц на дашборд</li>
          <li><?= icon('check', '', 13) ?> 1 участник на дашборд</li>
          <li><?= icon('check', '', 13) ?> Все виджеты</li>
        </ul>
        <?php if (!is_logged_in()): ?>
          <a href="/register.php" class="btn btn--ghost" style="text-align:center">Начать бесплатно</a>
        <?php endif; ?>
      </div>

      <div class="about-plan about-plan--popular">
        <div class="about-plan__popular">Популярный</div>
        <div class="about-plan__head">
          <span class="about-plan__name">Level 1</span>
          <span class="about-plan__price">299 <small>₽/мес</small></span>
        </div>
        <ul class="about-plan__list">
          <li><?= icon('check', '', 13) ?> 5 дашбордов</li>
          <li><?= icon('check', '', 13) ?> 10 страниц на дашборд</li>
          <li><?= icon('check', '', 13) ?> 5 участников на дашборд</li>
          <li><?= icon('check', '', 13) ?> Приоритетная поддержка</li>
        </ul>
        <?php if (is_logged_in()): ?>
          <a href="/billing.php" class="btn btn--warm" style="text-align:center">Подключить</a>
        <?php else: ?>
          <a href="/register.php" class="btn btn--warm" style="text-align:center">Начать</a>
        <?php endif; ?>
      </div>

      <div class="about-plan about-plan--premium">
        <div class="about-plan__head">
          <span class="about-plan__name">Level 2</span>
          <span class="about-plan__price">699 <small>₽/мес</small></span>
        </div>
        <ul class="about-plan__list">
          <li><?= icon('check', '', 13) ?> Безлимит дашбордов</li>
          <li><?= icon('check', '', 13) ?> Безлимит страниц</li>
          <li><?= icon('check', '', 13) ?> Безлимит участников</li>
          <li><?= icon('check', '', 13) ?> Полный контроль над данными</li>
        </ul>
        <?php if (is_logged_in()): ?>
          <a href="/billing.php" class="btn btn--warm" style="text-align:center">Подключить</a>
        <?php else: ?>
          <a href="/register.php" class="btn btn--warm" style="text-align:center">Начать</a>
        <?php endif; ?>
      </div>

    </div>
  </section>

  <hr class="about-divider">

  <!-- КОРПОРАТИВНЫЙ РЕЖИМ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('share-2', '', 15) ?> Совместная работа</div>
    <h2 class="about-section__title">Корпоративный режим</h2>
    <div class="about-section__body">
      <p>
        Любой дашборд можно открыть для других пользователей. Владелец приглашает
        коллегу по email и назначает роль:
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
          <li><?= icon('check', '', 13) ?> Управление страницами</li>
          <li><?= icon('x', '', 13) ?> Удаление дашборда</li>
          <li><?= icon('x', '', 13) ?> Управление участниками</li>
        </ul>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- БЕЗОПАСНОСТЬ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('shield', '', 15) ?> Безопасность</div>
    <h2 class="about-section__title">Ваши данные — только ваши</h2>
    <div class="about-security">
      <div>
        <h3 class="about-security__title">Надёжность и приватность</h3>
        <div class="about-security__body">
          <p>
            Все данные хранятся в <strong>MySQL</strong> на вашем собственном сервере.
            Никаких сторонних облаков, аналитики или трекеров.
          </p>
          <p style="margin-top:.75rem">
            Пароли хэшируются через <code>bcrypt</code>. Все запросы защищены CSRF-токенами.
            Сессии — httpOnly-куки. Доступ к каждому виджету и странице проверяется на уровне
            сервера — наблюдатель физически не может отправить запрос на редактирование.
          </p>
          <p style="margin-top:.75rem">
            Каскадные внешние ключи обеспечивают целостность: при удалении дашборда
            автоматически удаляются все страницы и виджеты.
          </p>
        </div>
      </div>
    </div>
  </section>

  <hr class="about-divider">

  <!-- ФИЛОСОФИЯ -->
  <section class="about-section">
    <div class="about-section__tag"><?= icon('info', '', 15) ?> Философия</div>
    <h2 class="about-section__title">Меньше — значит больше</h2>
    <div class="about-section__body">
      <p>
        Мы намеренно отказались от десятков «умных» функций: нет ИИ-помощников,
        нет интеграций с десятками сервисов, нет геймификации.
      </p>
      <p>
        Есть только чистый инструмент — в одиночку или с командой.
        Point of Creation — это точка, в которой рождается то, что важно именно вам.
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
        <a href="/billing.php" class="about-footer__link">Подписка</a>
        <a href="/settings.php" class="about-footer__link">Настройки</a>
      <?php else: ?>
        <a href="/login.php" class="about-footer__link">Войти</a>
        <a href="/register.php" class="about-footer__link">Регистрация</a>
      <?php endif; ?>
    </nav>
  </div>
</footer>

<style>
  /* Тарифы на about */
  .about-plans {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
  }

  .about-plan {
    position: relative;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.5rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
  }

  .about-plan--popular {
    border-color: var(--amber);
  }

  .about-plan--premium {
    border-color: var(--border2);
  }

  .about-plan__popular {
    position: absolute;
    top: -10px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--amber);
    color: var(--bg);
    font-size: .7rem;
    font-weight: 700;
    padding: 2px 12px;
    border-radius: 20px;
    white-space: nowrap;
  }

  .about-plan__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: .5rem;
  }

  .about-plan__name {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text);
  }

  .about-plan__price {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--amber);
  }

  .about-plan__price small {
    font-size: .72rem;
    color: var(--text3);
    font-weight: 500;
  }

  .about-plan__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: .4rem;
    flex: 1;
  }

  .about-plan__list li {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .83rem;
    color: var(--text2);
  }

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
    background: var(--bg2);
  }

  .about-hier-item__icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius);
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
    color: #818cf8;
  }

  .about-hier-item__icon--3 {
    background: rgba(16, 185, 129, .12);
    color: #34d399;
  }

  .about-hier-item__title {
    font-weight: 700;
    color: var(--text);
    margin-bottom: .25rem;
  }

  .about-hier-item__desc {
    font-size: .85rem;
    color: var(--text2);
    line-height: 1.55;
  }

  .about-hier-arrow {
    display: flex;
    justify-content: center;
    padding: .25rem 0;
    color: var(--text3);
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
    background: var(--bg2);
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
    color: var(--text);
  }

  .about-role__badge {
    margin-left: auto;
    font-size: .72rem;
    padding: 2px 8px;
    border-radius: 20px;
    background: var(--card);
    color: var(--text3);
    font-weight: 600;
    font-family: monospace;
  }

  .about-role__badge--editor {
    background: rgba(99, 102, 241, .15);
    color: #818cf8;
  }

  .about-role__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    font-size: .84rem;
    color: var(--text2);
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