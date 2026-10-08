# AutoRepair AI Hosting Beta

AutoRepair AI Hosting Beta は、WordPress サイトの**独立した診断・復旧・隔離環境での復元リハーサル**を行うプラグインです。バックアップの保存状態は別経路で検証されます。

## 正規の配布物（重要）

**このリポジトリで配布している正規版は、最新の `autorepair-ai-hosting-beta-<version>.zip` だけです。**

- 正規版: `autorepair-ai-hosting-beta-0.20.30.zip`
- 自動更新フィード: [`update.json`](./update.json)（`version` / `download_url` / `sha256` を保持）
- プラグイン本体のソースは **zip の中だけ**にあります。リポジトリ直下に同名フォルダは置いていません（二重管理の防止）。
- 過去バージョンの zip は互換性確認用に残していたものですが、現在は **最新版のみを正**とします。古い zip は予告なく削除します。

## 動作要件

| 項目 | 要件 |
|---|---|
| WordPress | 6.2 以上（6.8 でテスト済み） |
| PHP | 7.4 以上 |
| 必須拡張 | ZipArchive（バックアップ機能を使う場合） |
| その他 | 単一サイト構成（マルチサイトは対象外） |

## インストール

1. 最新の zip をダウンロードします。
2. WordPress 管理画面 →「プラグイン」→「新規追加」→「プラグインのアップロード」で zip を選択し、有効化します。
3. 左メニューの「Hosting Beta」から「かんたん接続」を開き、診断する側と管理する側の接続情報を設定します。

## 更新

プラグインは [`update.json`](./update.json) を参照して更新の有無を判定します（成功時 6 時間、失敗時 30 分キャッシュ）。管理画面の「プラグイン」→「更新を確認」から更新できます。

## 構成

| パス | 内容 |
|---|---|
| `autorepair-ai-hosting-beta-<version>.zip` | プラグイン本体（正規の配布物） |
| `update.json` | 自動更新フィード |
| `central-service/` | 中央サーバー（Render 上で稼働、`service.py`） |
| `ci/` | 受け入れテスト・Stripe サンドボックス・負荷計測のスクリプト |
| `.github/workflows/acceptance.yml` | CI（AutoRepair acceptance） |

## ライセンス

このリポジトリが配布するプラグイン本体（`autorepair-ai-hosting-beta-<version>.zip` に含まれる PHP / JavaScript / CSS / Python）は **GNU General Public License v2.0 以降（GPL-2.0-or-later）** の下で配布されます。全文はリポジトリ直下の [`LICENSE`](./LICENSE) を参照してください。

| 対象 | ライセンス |
|---|---|
| プラグイン本体（zip 内の全ファイル） | GPL-2.0-or-later |
| 中央サービス（`central-service/`） | プラグイン本体とは別のライセンス（未確定） |
| 同梱する第三者コード | ありません（[`THIRD-PARTY-NOTICES.md`](./THIRD-PARTY-NOTICES.md)） |

GPL は販売を禁じていません。ただし販売した場合、購入者には GPL が定める再配布・改変の自由が及びます（GPL-2.0 第1条は、複製物の引き渡しに対して対価を請求できると定めています）。


---

## English summary

AutoRepair AI Hosting Beta is a WordPress plugin for independent diagnostics, recovery, and isolated restore rehearsal with separately verified backup status.

**Canonical distribution:** the latest `autorepair-ai-hosting-beta-<version>.zip` (currently `0.20.30`) plus `update.json`. The plugin source ships only inside the zip; there is no duplicate source folder at the repository root. Older zips are not canonical and may be removed.

**Requirements:** WordPress 6.2+ (tested to 6.8), PHP 7.4+, ZipArchive for backup features, single-site only.

**License:** GPL-2.0-or-later (see [`LICENSE`](./LICENSE)). Third-party code bundled: none.
