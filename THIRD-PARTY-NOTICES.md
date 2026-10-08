# Third-party notices

最終更新: 2026-10-08（プラグイン 0.20.30 時点）

## 1. 同梱している第三者コード

**ありません。**

本リポジトリが配布する `autorepair-ai-hosting-beta-<version>.zip` に含まれるのは自作の PHP / JavaScript / CSS / Python のみで、第三者のライブラリ・SDK は同梱していません。

実測による確認結果:

| 確認項目 | 結果 |
|---|---|
| `composer.json`（PHP の依存定義） | 存在しない |
| `package.json`（JS の依存定義） | 存在しない |
| `vendor/` ディレクトリ | 存在しない |
| JS の第三者ライブラリ（jQuery / lodash / moment / Bootstrap / Chart.js 等） | 参照なし（6 ファイル全検索） |
| CSS の外部参照（`@import` / `url(http…)`） | なし（4 ファイル全検索） |
| `restore-test/runner.py` の import | Python 標準ライブラリのみ |
| `central-service/requirements.txt` | 「第三者依存なし（標準ライブラリのみ、設計上）」と明記 |
| Freemius SDK | 0.10.0 で同梱・起動を停止（`THIRD-PARTY.md` 参照） |
| プラグイン内で GPL 以外のライセンス表示を持つファイル | なし（GPL 表示は本体 PHP ヘッダのみ） |

## 2. 利用者が実行時に取得するもの（本リポジトリは同梱・再配布していない）

隔離復元リハーサルは、利用者の環境で次の Docker イメージを取得・ビルドして使用します。本リポジトリはこれらのイメージおよびバイナリを配布していません。

| イメージ | 取得方法 |
|---|---|
| `php:8.3-cli` | `restore-test/Dockerfile` の `FROM` として利用者が `docker build` |
| `mysql:8.4` | 利用者が `docker pull` |
| `mariadb:10.5` | 利用者が `docker pull` |

本リポジトリによる再配布がないため、これらイメージのライセンス条項が本リポジトリの配布物に付随することはありません（一般的な理解であり、法的助言ではありません）。各イメージの条件は配布元の表示に従ってください。

## 3. 動作環境（同梱していない）

WordPress、PHP、MySQL / MariaDB は利用者の環境に既に存在するものを利用します。本リポジトリはこれらを同梱・再配布していません。
