# 中央利用数サーバー 0.1（試作）

Python 3.11以降・標準ライブラリのみ。WordPressの公開ディレクトリとは別の場所に設置してください。
この版は顧客が申告した登録台帳の観測・照合用です。商用課金、支払い認証、無制限の処理能力を提供するものではありません。

## 自分のPCで試す
このフォルダで実行します。

```
python3 -m unittest -v
python3 service.py --db usage.sqlite3 provision demo hub1 --base 10000 --unit 100
python3 service.py --db usage.sqlite3 serve --port 8787
```

provisionは最初の1回だけ実行します。表示されたhub_tokenを安全に保管してください。同じdemo顧客でhub2を作ると複数拠点を合算できます。顧客が違う場合はaccount名を変えます。料金は検証値です。

請求期間は顧客ごとの契約日基準です。`--anchor-day`(1〜28)を省略すると、そのaccountを最初にprovisionした日(UTC)が契約日になります。同じaccountに複数拠点(hub)を追加する場合、2回目以降のprovisionでは`--anchor-day`を省略するか、最初と同じ値を指定してください(異なる値は拒否されます)。

中央での登録承認は、`ADMIN_TOKEN`を持つ運用者だけが`provision`できるという形で行います(自己登録はできません)。契約終了・不正利用が疑われる拠点は、次のコマンドでトークンを失効させます(取り消しは取り消せません)。

```
python3 service.py --db usage.sqlite3 revoke hub1
```

失効した拠点は以後の同期・状態確認が401で拒否され、その拠点が登録していたサイトは即座に集計から除外されます(既に確定した過去の周期のpeakは書き換わりません)。

滞納など、顧客(account)単位でまとめて一時停止したい場合はこちらです。複数拠点を持つ顧客でも1回の操作で全拠点が止まります。

```
python3 service.py --db usage.sqlite3 suspend demo
python3 service.py --db usage.sqlite3 unsuspend demo
```

`revoke`と違い、`suspend`中はサイト登録数を消しません(既存のサイト数はそのまま集計され続けますが、同期・状態確認は401で拒否されます)。支払い再開後に`unsuspend`すれば、それまで通り再開できます。

**滞納の自動suspend/unsuspend(実装済み)**: `invoice.payment_failed`を受けると、`service.handle_stripe_event`が自動的に`suspend(account, reason='payment_failed')`を実行します。その後`invoice.paid`を受けると、**そのaccountの`suspend_reason`が`payment_failed`である場合に限り**自動で`unsuspend`します。運用者が別の理由(不正利用など)で手動suspendしたアカウントは、無関係な支払い成功イベントで勝手に復活しません(理由を記録して区別しているため)。手動での`suspend`/`unsuspend`コマンドも引き続き使えます。

### 支出上限(警告表示のみ、実装済み)
顧客ごとに、月額試算がいくらを超えたら警告するかを設定できます。**利用を止めることはありません**(新規サイト登録も同期も引き続き可能です)。顧客自身が管理用トークンで設定します(下記「複数拠点の自己追加」と同じ認証方式)。

```
POST /v1/manage/spending-cap
Authorization: Bearer <管理用トークン>
{"spending_cap_yen": 20000}
```

`/v1/usage`・`/v1/snapshot`の応答に`spending_cap_yen`(設定値、0は未設定)と`over_spending_cap`(超過しているか)が含まれるようになりました。WordPress管理画面(`central.php`)にも、超過時の警告表示を追加済みです。

サーバーは127.0.0.1:8787で動きます。WordPressとの接続には、有効な証明書のある公開HTTPS経由でこのサーバーへ到達できるリバースプロキシが必要です。プラグイン側はローカルHTTPを許可しません。
インターネット公開前に認証運用・バックアップ・監視・アクセス制限・レート制限を整えてください。`serve`は標準ライブラリの`ThreadingHTTPServer`で動きます(負荷検証の結果、単一処理サーバーだと同時アクセスで接続拒否が起きたため)。それでも本格的な負荷分散・ワーカープール等ではありません。

## データの信頼性(SQLiteのまま)
外部ライブラリを増やさず、標準ライブラリの範囲で信頼性を高めています。

- **WALモード**: プロセスが書き込み途中で落ちてもファイルが壊れにくい形式(`journal_mode=WAL`、`synchronous=NORMAL`)。新規・既存どちらのDBファイルでも自動的に有効になります。
- **オンラインバックアップ**: サーバーを動かしたまま、矛盾のない一貫したコピーを取れます(ファイルを直接コピーすると書き込み途中の状態を拾ってしまう危険があるため)。

```
python3 service.py --db usage.sqlite3 backup usage-backup-$(date +%Y%m%d).sqlite3
```

- **整合性チェック**: 破損の有無を確認します。壊れていれば`ok`以外が返り、終了コードも非0になります。

```
python3 service.py --db usage.sqlite3 check
```

Renderで運用する場合は、これらを`cron`相当の定期ジョブ(RenderのCron Jobs機能など)で毎日実行し、バックアップ先は永続ディスクの外(別サービスへのアップロード等)に置くことを推奨します。PostgreSQL等への本格移行は、実際に複数インスタンスでの水平スケールが必要になった段階で改めて検討します(それまでは標準ライブラリのみという設計方針を優先)。

## 負荷検証
`loadtest.py`(標準ライブラリのみ、追加依存なし)で、実際に複数拠点が同時にアクセスした場合の挙動を計測できます。

```
python3 loadtest.py --hubs 20 --requests-per-hub 25 --concurrency 20 --threaded
```

**分かったこと**:
- 従来の単一処理サーバー(`HTTPServer`)は、20拠点が同時にアクセスするだけで接続拒否エラーが発生していました(受付キューが溢れるため)。同時アクセスが少ない小規模検証では表面化しませんでしたが、実運用では危険な状態でした。
- `ThreadingHTTPServer`(標準ライブラリの範囲内)に切り替えたことでこのエラーは解消しました。`serve`コマンドは既にこちらに変更済みです。
- 現実的な規模(同時5拠点)では、応答時間の中央値約40ms・99パーセンタイルでも約400msと、WP-Cronの実行間隔(既定1時間ごと)に対して十分速いです。
- 同時50拠点というかなりの負荷をかけると、エラーは出ないものの、SQLiteの書き込みが1本ずつ順番に処理される制約により、応答時間が数秒まで伸びるケースがあります。同時に大量の拠点が全く同じ瞬間に書き込もうとする状況が常態化するようなら、この時点でPostgreSQL等への移行を検討します(現時点の想定顧客数ではまだ先の話です)。

## WordPressと接続する
1. 同梱のプラグインZIPをテスト用WordPressへアップロードして有効化。
2. 「AI運用ホーム → 利用数と料金」を開く。
3. 中央サーバーの公開HTTPS URLと、その管理拠点専用hub_tokenを入力。
4. 「接続・同期を実行」。送信待ちがある場合は順番に同期。自動処理はWP-Cronに依存。

中央URLは /v1/snapshot の手前まで指定します。Stripeの秘密鍵をこの画面に入力しないでください。
トークンは中央DBにはハッシュ、WordPress側には暗号化して保存します。中央へ送るのはURLそのものではなく、接続URLから得たSHA256のサイトID一覧です。ハッシュは匿名化の保証ではありません。

## API
Authorization: Bearer <拠点トークン>

POST /v1/snapshot
```
{"sequence":1,"sites":["64桁のSHA256サイトID"]}
```
GET /v1/usage

同一sequence・同一内容の再送は重複計上しません。古いsequenceや同一番号で内容が違う送信は409で拒否します。sequenceを初期化せず、WordPressを複製する場合は新しいhubを発行してください。復元・移転時は送信待ちと中央の受信番号を照合します。運用者用のトークンローテーション・拠点削除UIは未実装です。

## 数え方と未確定の扱い
- 顧客単位で全拠点のサイトIDの和集合を数えます。同じURLの登録は重複排除。別のURL表記は別IDになる場合があります。
- 請求期間は暦月ではなく、顧客ごとの契約日(anchor_day、UTC)を起点とした周期です。中央の受信順で観測した、その周期内の最大同時登録数を保持します。
- 送信待ちは追加・削除の順に保持します。ただし遅延した記録を過去の周期へ戻す仕組みはありません。遅れがある周期は請求に使えません。
- 遅延受付期限: 周期終了後、その顧客のいずれかの拠点(hub)が周期終了時点以降にまだ一度も同期していない場合、`export`は72時間はエラーで拒否されます。72時間経過後は、それでも未同期の拠点数(`unsynced_hubs`)を結果に含めたうえでexportできます。至急の事情がある場合のみ`--force`(CLI)/`force=True`(API呼び出し)で72時間以内でも上書きできますが、その分の金額は未確定データを含む可能性があります。
- 通信断を削除とみなしません。2時間以上未同期の拠点数を表示します。
- WordPress側で記録ロック競合・保存失敗があれば、欠落の可能性を表示します。自動解除せず照合が必要です。
- 計測がなかった過去月は推測して作りません。月額基本料と単価は、その月の最初の観測時点で記録します。
- 顧客がWordPressコードを変更して過少申告することは防げません。商用化には中央側での登録承認・サービス提供権限との連動が必要です。

## Stripeを試す
集計をJSONで書き出します。YYYY-MM-DDは、観測結果(`/v1/usage`応答や同期後の画面)に表示される請求期間の開始日に置き換えます。

```
python3 service.py --db usage.sqlite3 export demo YYYY-MM-DD > usage.json
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID
```

上は送信予定のプレビューだけで、通信しません。Stripeテスト環境に顧客を作成後、サーバー環境変数STRIPE_TEST_SECRET_KEYへテスト秘密鍵を設定すると、次を実行できます。

```
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --send-test-draft
```

sk_test_以外は拒否します。下書き請求書にJPYの試算額を1行追加します。auto_advance=falseで、下書き作成自体は自動で先に進みません。外部APIの結果はstripe-stateへ保存します。重複処理は冪等キーとローカル記録で抑止。金額を変えて同じ顧客・月へ再送すると停止します。応答不明から20時間以上たった再試行は手動照合が必要です。stripe-stateを削除して再送しないでください。
この試験経路はStripe Billing Meters/Metronomeの本番採用を決めるものではありません。試算の整合性を先に確認するためのDraft Invoice連携です。

### 確定・送信(テストモードのみ)
下書きが完成したら(`--send-test-draft`が成功したら)、確定して顧客に送れます。

```
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --finalize
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --deliver
```

- `--finalize`: 下書きを確定し、Stripe上で「open(支払い待ち)」状態にします。金額はもう編集できなくなります。
- `--deliver`: 確定した請求書をStripeのホスト型請求書ページ経由で顧客にメール送信します。

**重要**: 請求書の`collection_method`は常に`send_invoice`です。`charge_automatically`(保存済みカードへの自動課金)は使いません。つまりこの仕組みは顧客に請求書を届けるところまでで、**実際の引き落とし・課金処理は一切行いません**。顧客がStripeのホスト型ページで自分で支払う操作をします。支払い結果(成功・失敗)はWebhook経由でこちらに届きますが、現時点ではその結果を受けて自動で何かする処理(督促・自動suspendなど)はまだ実装していません(次項参照)。

### 訂正
金額を間違えて下書きを作ってしまった場合は、`--correct`で理由付きの訂正記録を残してから、正しい金額で再送します。

```
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --correct "大阪拠点の重複を除外して再計算" --state-dir stripe-state
```

これはStripe側には一切触れません。元のstripe-state記録を`<識別子>.corrected-<時刻>.json`として保存し、元ファイルを削除するだけです。アーカイブには元の`invoice`/`item`のIDが残るので、**運用者がStripeダッシュボードでその下書きを手動で削除**してください。その後、修正した金額を含むusage.jsonで通常通り`--send-test-draft`を実行すれば、新しい下書きが作られます。同じ提出を理由なく2回訂正することはできません(1回目の訂正後、まだ再送していない状態で再度`--correct`を呼ぶとエラーになります)。

### 支払い結果の自動記録と滞納自動処理(実装済み)
請求書には`account`/`month`を`metadata`として付与するようにしたので(`stripe_draft.plan`)、`invoice.paid`/`invoice.payment_failed`のWebhookイベントが届くと、`service.handle_stripe_event`が自動的に`payments`テーブルへ記録します(`Store.record_payment`/`Store.payment_status`)。

さらに、支払い失敗は自動suspend・支払い成功による自動unsuspendも実装済みです(詳細は上の「中央の登録承認」の項参照)。運用者による手動suspendは、無関係な支払い成功で上書きされないよう理由で区別しています。

### 返金(全額・部分返金対応、記録のみ)
支払い済みの周期を誤って請求していたことが後から分かった場合、`--refund`で返金の決定を記録します。`--correct`と同じ考え方で、**Stripe側には一切触れません**。

```
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --refund "計算誤りのため全額返金"
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --refund "大阪拠点分のみ過大請求" --refund-amount 300
```

`--refund-amount`を省略すると全額返金、指定すると部分返金になります(元の請求額を超える金額は拒否)。

事前に`service.py`側で`Store.payment_status(account, month)`が`paid`であることを確認してから実行してください(このコマンド自体は支払い済みかどうかを確認しません)。実行すると、そのstripe-state記録に`refunded: true`・返金額・元の請求額(`refund_full_amount`)・理由・時刻が書き込まれます。記録された`invoice`/`item`のIDを使って、**運用者がStripeダッシュボードで実際の返金操作を行って**ください。同じ提出を2回「返金済み」にすることはできません。完了していない(まだ`--send-test-draft`していない)提出は返金できません。

### Webhook
`stripe_draft.verify_webhook(payload, sig_header, secret, now=None, tolerance=300)`で、StripeのWebhookリクエストが本物かどうかを検証できます。`payload`は生のリクエストボディ(バイト列)、`sig_header`はリクエストの`Stripe-Signature`ヘッダー、`secret`はStripeダッシュボードで発行した`whsec_...`を使います。タイムスタンプが現在時刻から5分(既定値)以上ずれていたり、署名が一致しなければ`ValueError`になります。

実際に待ち受けるHTTPエンドポイントも用意しました。

```
POST /v1/stripe/webhook
```

環境変数`STRIPE_WEBHOOK_SECRET`(`whsec_...`)を設定しないと常に401を返します。設定すると、署名を検証したうえでイベントを`stripe_events`テーブルに記録します(同じイベントIDの再配信は`new: false`を返し、二重処理しません)。

**現時点でこのエンドポイントがやること**: 検証・記録に加えて、次のイベントは実際にアカウント操作へ配線済みです(`service.handle_stripe_event`)。

| イベント | 動作 |
|---|---|
| `checkout.session.completed` | Stripeオブジェクトの`metadata`に`account`・`hub`(・任意で`base`/`unit`)があれば、自動で`provision`してhub_tokenを発行。メールアドレスが分かればhub_token・管理用リンクをメール送信 |
| `customer.subscription.created` | `metadata`に`account`があり、サブスクリプションに明細行(item)が1つ以上あれば、そのサブスクリプションID・明細行IDを`accounts`テーブルに記録(下記「利用数のStripe同期」参照) |
| `customer.subscription.deleted` | `metadata`に`account`があれば、自動で`suspend`(理由: `subscription_canceled`) |
| `invoice.paid` | 支払い成功を`payments`テーブルに記録。そのaccountが`payment_failed`理由でsuspend中なら自動`unsuspend`(他の理由でのsuspendは維持) |
| `invoice.payment_failed` | 支払い失敗を`payments`テーブルに記録し、自動`suspend`(理由: `payment_failed`) |
| それ以外 | 記録のみ |

`metadata`が無い・不正・拠点IDが既存と衝突する場合は、`{"action":"skipped","reason":...}`を返すだけでエラーにはしません(Stripeの延々としたリトライを避けるため)。**拠点ID(hub)は全account共通で一意である必要があります**(既存の設計通り)。

### 購入/解約ポータル(実装済み)
自己申込・自己解約を実現するには、今までの「お金が動く操作は運用者が手動で行う」という前提を初めて越える必要があります。着手する際は、以下の設計方針で進める想定です。

**課金方式**: Stripeのサブスクリプション機能(Billing Meters等)には乗り換えません。既に検証済みの「中央サーバーが周期ごとのpeakを計算し、`stripe_draft.py`が請求書に1行追加する」という現在の仕組みをそのまま活かし、Checkoutでは基本料金分の定期支払いのみを作成する設計にします。理由: 使用量の計算ロジック(合算・重複排除・遅延受付期限など)を今から作り直すのは手戻りが大きいためです。

**申込フロー**:
1. 顧客がStripe Checkout(サブスクリプションモード、基本料金の価格をStripeダッシュボードで事前作成)で支払い方法を登録。Checkout Session自体は`stripe_draft.create_checkout_session(account, hub, price_id, success_url, cancel_url, key, ...)`で作成できます(**実装済み**)。`account`/`hub`/`base`/`unit`をCheckout SessionとSubscriptionの両方のmetadataにセットするので、下記のWebhook処理がどちらのイベントからでも読み取れます
2. Stripeから`checkout.session.completed`のWebhookイベントが届く
3. 中央サーバーが`verify_webhook()`で検証してから、`Store.provision()`を自動実行してhub_tokenを発行(**実装済み**)
4. 発行したhub_tokenを顧客に渡す方法が課題として残ります(画面に一度だけ表示・メール送信のいずれかが必要。メール送信は現状の「標準ライブラリのみ」という方針に収まる範囲で設計する必要があります)

**公開申込エンドポイント(実装済み)**:

```
POST /v1/signup
{"email": "customer@example.com"}   # emailは任意
```

環境変数`STRIPE_PRICE_ID`・`SIGNUP_SUCCESS_URL`・`SIGNUP_CANCEL_URL`・`STRIPE_TEST_SECRET_KEY`のいずれかが未設定なら404(機能自体が無いように見せます)。`account`/`hub`は顧客からは受け取らず、`secrets.token_hex`でランダム発行します(推測・衝突を防ぐため)。基本料金・単価は環境変数`SIGNUP_BASE`・`SIGNUP_UNIT`(省略時10000円/100円)。レスポンスは`{"url": "https://checkout.stripe.com/..."}`です。

**テスト購入画面(実装済み)**: `GET /signup`(上と同じ環境変数が揃っていないと404)。ボタン1つで`POST /v1/signup`を呼び、返ってきたURLへブラウザをリダイレクトするだけの最小限のHTMLページです。テストモードである旨を明記しています。

同一IPから10分間に5回を超えるリクエストは429で拒否します(プロセス内メモリのみの簡易実装。再起動でリセットされ、複数インスタンス構成では共有されません)。

Stripeの商品/価格(`price_...`)自体は、Stripeダッシュボードで先に作成しておく必要があります(これは運用者の作業です)。

**解約フロー**:
1. StripeのCustomer Portal(Stripeが提供する既製の自己管理画面。自前でUIを作らずに済みます)で顧客が解約
2. `customer.subscription.deleted`のWebhookイベントを受信・検証
3. 該当accountに対して自動で`Store.suspend()`を実行(`revoke`ではなく`suspend`。再契約時に復元できるようにするため)(**実装済み**)

**既に配線・実装済み**:
- `checkout.session.completed`→`Store.provision()`、`customer.subscription.created`→サブスクリプションID記録、`customer.subscription.deleted`→`Store.suspend()`(`service.handle_stripe_event`、上の「Webhook」の項)
- `stripe_draft.create_checkout_session()`(Checkout Session作成の関数自体)
- `POST /v1/signup`・`GET /signup`(公開申込エンドポイントとテスト購入画面。account/hubの自動採番、レート制限込み。上の「Webhook」の項参照)
- 複数拠点の自己追加(`POST /v1/manage/hubs`。下記「複数拠点の自己追加」参照)
- Customer Portalへの公開導線(管理用リンク方式。下記参照)

### 既存拠点の自己アップグレード(実装済み: WordPress管理画面互換)
上の「申込フロー」は、まだhub_tokenを持たない新規顧客が中央サーバーの公開`/signup`ページから始める経路です。これとは別に、**既にhub_tokenでWordPressに接続済みの顧客が、その管理画面内のボタンから有償プランへアップグレードする**という、別の経路も必要になる場面があります(旧版で先に作っていたWordPress側の購入導線がこの経路を前提にしていたため、後方互換として維持しています)。

```
POST /v1/stripe/test-checkout
Authorization: Bearer <拠点(hub)トークン>
{"success_url": "https://...", "cancel_url": "https://..."}

GET /v1/stripe/test-status
Authorization: Bearer <拠点(hub)トークン>

POST /v1/stripe/test-portal
Authorization: Bearer <拠点(hub)トークン>
{"return_url": "https://..."}
```

- 認証は管理用トークンではなく、既存の拠点(hub)トークンです。呼び出し元の拠点が属するaccountに対してCheckout/ポータルを作成するため、他人のaccountを指定することはできません
- 内部的には`/v1/signup`と同じ`create_checkout_session()`・Webhook経由の`customer.subscription.created`配線を再利用しています。呼び出し時点で拠点は既に存在するため、Webhook側の`Store.provision()`は「既に登録済み」として静かにスキップされるだけです(実害なし)
- `accounts`テーブルに`stripe_customer_id`列を追加し、`customer.subscription.created`イベントの`customer`をあわせて記録するようにしました。これにより、`/signup`経由で発行される管理用トークンを持たないaccountでも、ポータルセッションを開けます
- 既に有効(`active`/`trialing`/`past_due`/`unpaid`)なサブスクリプションがある状態で`test-checkout`を呼ぶと、Stripeへ都度問い合わせたうえで409を返します(二重契約の防止)
- `test-status`は、記録済みのサブスクリプションIDがあればStripeへ都度問い合わせて最新状態を返します(Webhookの到着順崩れ対策)

### 利用数のStripe同期(実装済み: サブスクリプション数量)
請求書に1行追加する既存の方式(`stripe_draft.py`の`send`/`finalize`/`deliver`、運用者が手動実行)とは別に、**当月の最大サイト数を、Stripeのサブスクリプション明細行(subscription item)の数量へ自動反映**します。金額を動かす操作ではなく、Stripe側の表示をこちら側の実態に合わせるだけの同期です。

- `checkout.session.completed`に続けて届く`customer.subscription.created`イベントから、サブスクリプションID・明細行ID(`si_...`)を`accounts`テーブルへ記録します(1明細行のみの構成を前提)
- 拠点(hub)が`POST /v1/snapshot`を送信するたびに、その周期の最新peakを`stripe_draft.sync_subscription_quantity()`で明細行の数量へ反映します(`proration_behavior=none`で日割り課金は発生させません)
- サブスクリプション未記録・`STRIPE_TEST_SECRET_KEY`未設定・Stripe側の一時的な失敗は、いずれも黙ってスキップします(拠点の同期応答自体は失敗させません。ピークは既にローカルへ確定記録済みのため、Stripe側の反映が後から追いつけば十分という考え方です)

**まだ実装していない、残りの部分**:
- なし(このセクションで計画していた項目は完了)

### Customer Portalへの公開導線(実装済み: 管理用リンク方式)
フルのログイン機構(会員登録・パスワード・セッション管理)は作らず、既存の`hub_token`と同じ「持っていれば使えるベアラートークン」方式で本人確認を代替しています。

`checkout.session.completed`を受けて自動provisionする際、`Store.issue_management_token(account, stripe_customer)`でアカウントごとに1つ、ランダムな管理用トークンを発行します(再発行すると古いトークンは自動的に無効化されます)。環境変数`MANAGE_BASE_URL`を設定していれば、hub_tokenと同じメールに次のリンクが自動的に含まれます。

```
GET /v1/manage/portal?token=<管理用トークン>
```

このリンクをクリックすると、`stripe_draft.create_portal_session()`でその場でStripeのCustomer Portalセッションを作成し、302リダイレクトで直接そこへ送ります(環境変数`PORTAL_RETURN_URL`・`STRIPE_TEST_SECRET_KEY`が必要。未設定なら404)。トークンが不正なら401。

**セキュリティ上の性質**: パスワードもメールアドレス入力も不要な代わりに、**このリンクを知っている人は誰でも操作できます**(メールを盗み見られる、リンクを他人に転送する、などのリスク)。hub_tokenと同じ考え方で、64桁のランダム値なので推測は現実的に不可能ですが、リンク自体の管理は顧客の責任になります。運用者が代行してリンクを再発行したい場合は、引き続き`stripe_draft.py --portal-customer cus_xxx --portal-return-url https://...`をCLIから直接実行できます(このコマンド自体は本人確認を行わないので、運用者自身が別途本人確認したうえで使ってください)。

### 複数拠点の自己追加(実装済み)
既存accountの管理用トークンを持っていれば、追加の拠点(hub)を自分で発行できます。

```
POST /v1/manage/hubs
Authorization: Bearer <管理用トークン>
```

レスポンス: `{"hub": "hub-xxxxxxxx", "hub_token": "..."}`。価格(基本料金・単価・契約日)は必ずそのaccountの既存設定を引き継ぎ、呼び出し側が指定することはできません。次の場合は拒否します。

- accountがsuspend中(滞納時など):「顧客自身がサービスを拡大しようとする」ことを防ぎます
- 1account内の拠点数が50を超える場合(実際の上限というより、乱用防止の安全弁です)

新しく発行された`hub_token`はレスポンスにそのまま含まれます(この呼び出しはWebhookと違い、レスポンスの届く先が顧客自身のブラウザ/クライアントなので、メール配達は不要です)。

### hub_tokenのメール配達(実装済み)
`mailer.py`(標準ライブラリの`smtplib`のみ、追加依存なし)で、汎用のSMTP経由メール送信ができます。Gmail・SendGrid・Renderが対応する任意のSMTPサービスを使えます。

環境変数: `SMTP_HOST`・`SMTP_PORT`(既定587)・`SMTP_USER`・`SMTP_PASSWORD`・`SMTP_FROM`。

`checkout.session.completed`を受けて自動provisionした際、Checkout Sessionの`customer_details.email`(または`customer_email`)にメールアドレスがあれば、`mailer.send()`で自動的にhub_tokenを送信します(`service.handle_stripe_event`)。**送信に失敗してもprovision自体は取り消しません**(先に成功しているアカウント作成を、後から起きたメール配送エラーで無かったことにはしない設計)。レスポンスの`email_delivered`が`false`の場合、運用者が`stripe_events`テーブルや自分の記録を見て手動でフォローする必要があります。

SMTP環境変数が未設定の場合は送信自体が失敗として扱われ(`email_delivered: false`)、provisionだけは成功します。`MANAGE_BASE_URL`を設定していれば、同じメールに管理用リンク(上記「Customer Portalへの公開導線」参照)も含まれます。

## 商用化の次工程
済み: 価格・課金対象・締日(契約日基準)・遅延受付期限(72時間)・訂正(下書き段階のみ)・返金(全額・部分返金、記録のみ)・拠点トークンの失効化・確定/送信(テストモード、send_invoiceのみ)・Webhook署名検証と受信エンドポイント・Webhookからの自動provision/自動suspend配線・支払い結果の自動記録と滞納自動suspend/unsuspend(理由追跡付き)・**支出上限(警告表示のみ)**・Checkout Session作成関数・テスト購入画面`GET /signup`と公開申込エンドポイント`POST /v1/signup`(レート制限込み)・hub_tokenのメール自動配達・Customer Portalへの公開導線(管理用リンク方式)・複数拠点の自己追加(`POST /v1/manage/hubs`)・**既存拠点の自己アップグレード(WordPress管理画面互換、`/v1/stripe/test-checkout`・`test-status`・`test-portal`)**・**利用数のStripeサブスクリプション数量への自動同期**・データの信頼性向上(WAL・バックアップ・整合性チェック、SQLiteのまま)・負荷検証(ThreadingHTTPServerへの切り替えで接続拒否を解消)。
決定済みで今回は実装しない: 本格的なPostgreSQL移行(複数インスタンス化が必要になるまで)、本格的なログイン機構(会員登録・パスワード。代わりにベアラー式の管理用トークンで自己サービスを実現)。
残り: **実環境での結線テスト**(テストモードのStripeカードで、購入→サブスクリプション作成→サイト数変更に伴う数量同期→支払い失敗/解約時の新規登録停止、までを通しで確認)。コードとしては揃っていますが、`STRIPE_SECRET_KEY`・`STRIPE_WEBHOOK_SECRET`をRenderへ設定し、Stripeダッシュボード側のWebhook宛先登録を済ませてからでないと検証できません。ここまでで**自己申込から利用開始・契約管理(拠点追加・解約・支出上限設定)・滞納の自動処理・訂正・返金・Stripe数量同期までの一連の流れの部品が揃いました**。実際に使うにはStripeダッシュボードでの商品/価格作成・SMTP設定・`MANAGE_BASE_URL`等の環境変数設定も必要です。
この版のSQLite・全件スナップショット・WordPressオプション送信待ちは小規模検証向けです。10万件という入力上限は処理実績ではありません。

公式資料:
https://docs.stripe.com/api/invoices/create
https://docs.stripe.com/api/invoiceitems/create
https://docs.stripe.com/billing/subscriptions/usage-based/recording-usage
