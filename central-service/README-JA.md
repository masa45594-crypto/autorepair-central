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
サーバーは127.0.0.1:8787で動きます。WordPressとの接続には、有効な証明書のある公開HTTPS経由でこのサーバーへ到達できるリバースプロキシが必要です。プラグイン側はローカルHTTPを許可しません。
インターネット公開前に認証運用・バックアップ・監視・アクセス制限・レート制限を整えてください。この標準HTTPServerは試験用の単一処理サーバーです。

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
- 月はUTC。中央の受信順で観測した最大同時登録数を保持します。
- 送信待ちは追加・削除の順に保持します。ただし遅延した記録を過去月へ戻す仕組みはありません。遅れがある月は請求に使えません。
- 通信断を削除とみなしません。2時間以上未同期の拠点数を表示します。
- WordPress側で記録ロック競合・保存失敗があれば、欠落の可能性を表示します。自動解除せず照合が必要です。
- 計測がなかった過去月は推測して作りません。月額基本料と単価は、その月の最初の観測時点で記録します。
- 顧客がWordPressコードを変更して過少申告することは防げません。商用化には中央側での登録承認・サービス提供権限との連動が必要です。

## Stripeを試す
集計をJSONで書き出します。YYYY-MMを記録のある月に置き換えます。

```
python3 service.py --db usage.sqlite3 export demo YYYY-MM > usage.json
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID
```

上は送信予定のプレビューだけで、通信しません。Stripeテスト環境に顧客を作成後、サーバー環境変数STRIPE_TEST_SECRET_KEYへテスト秘密鍵を設定すると、次を実行できます。

```
python3 stripe_draft.py usage.json --customer cus_テスト顧客ID --send-test-draft
```

sk_test_以外は拒否します。下書き請求書にJPYの試算額を1行追加します。auto_advance=falseで、確定・送信・支払いAPIは実装していません。外部APIの結果はstripe-stateへ保存します。重複処理は冪等キーとローカル記録で抑止。金額を変えて同じ顧客・月へ再送すると停止します。応答不明から20時間以上たった再試行は手動照合が必要です。stripe-stateを削除して再送しないでください。
この試験経路はStripe Billing Meters/Metronomeの本番採用を決めるものではありません。試算の整合性を先に確認するためのDraft Invoice連携です。

## 商用化の次工程
価格・課金対象・締日・遅延受付期限の確定、期間の締め処理、訂正・返金、中央の登録承認、契約/支払いの署名付きWebhook、購入/解約用ポータル、支出上限、滞納処理、PostgreSQL等の永続DBとワーカー、負荷検証。
この版のSQLite・全件スナップショット・WordPressオプション送信待ちは小規模検証向けです。10万件という入力上限は処理実績ではありません。

公式資料:
https://docs.stripe.com/api/invoices/create
https://docs.stripe.com/api/invoiceitems/create
https://docs.stripe.com/billing/subscriptions/usage-based/recording-usage
