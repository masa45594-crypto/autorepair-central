"""Minimal SMTP mail sender, standard library only (smtplib + email.message).
Used to deliver a hub_token to a customer after self-service signup, since a Stripe
webhook's HTTP response is never seen by the customer. Delivery is best-effort: a failure
here must never undo an already-completed provision() (see service.handle_stripe_event).
A transient failure is retried a bounded number of times with backoff, but a failure that
could have already handed the message over is not retried, so a customer never receives the
same token twice."""
import os, re, socket, smtplib, ssl, time
from email.message import EmailMessage

def config_from_env():
    return {'host':os.environ.get('SMTP_HOST',''),'port':int(os.environ.get('SMTP_PORT','587')),
            'user':os.environ.get('SMTP_USER',''),'password':os.environ.get('SMTP_PASSWORD',''),
            'from_addr':os.environ.get('SMTP_FROM','')}

def hub_token_email_body(account,hub,hub_token,manage_url=None):
    body=('お申し込みありがとうございます。\n\n'
          'アカウントID: '+account+'\n'
          '拠点ID: '+hub+'\n'
          '拠点トークン(WordPress管理画面の「利用数と料金」で入力してください):\n'
          +hub_token+'\n\n'
          'このトークンは他人に共有しないでください。\n')
    if manage_url:
        body+=('\n契約の確認・お支払い方法の変更・解約はこちらのリンクから行えます:\n'
               +manage_url+'\n'
               'このリンクを知っている人は誰でも契約を操作できるため、他人に共有しないでください。\n')
    return body

RETRY_ATTEMPTS=3
RETRY_BACKOFF=2.0
RETRY_CODES=range(400,500)

def _transient(exc,handed_over):
    """True only when retrying cannot duplicate a delivery.

    handed_over is True once send_message() has been entered: after that a dropped connection
    or a timeout leaves the outcome unknown, so it is never retried. A 4xx reply from the
    server is different - it means the message was rejected, so a retry is safe.
    """
    if isinstance(exc,smtplib.SMTPRecipientsRefused):
        codes=[int(c) for c,_ in exc.recipients.values()]
        return bool(codes) and all(c in RETRY_CODES for c in codes)
    if isinstance(exc,smtplib.SMTPResponseException):
        return int(getattr(exc,'smtp_code',0) or 0) in RETRY_CODES
    if isinstance(exc,(smtplib.SMTPServerDisconnected,socket.timeout,TimeoutError,socket.gaierror,
                      ConnectionRefusedError,ConnectionResetError,ssl.SSLError)):
        return not handed_over
    return False

def send(to_addr,subject,body,config=None,smtp_cls=smtplib.SMTP,sleep=time.sleep):
    config=config or config_from_env()
    if not config.get('host') or not config.get('from_addr'):raise ValueError('SMTP_HOST and SMTP_FROM must be configured')
    if not isinstance(to_addr,str) or not re.fullmatch(r'[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,24}',to_addr):raise ValueError('invalid recipient address')
    msg=EmailMessage();msg['Subject']=subject;msg['From']=config['from_addr'];msg['To']=to_addr;msg.set_content(body)
    for attempt in range(1,RETRY_ATTEMPTS+1):
        handed_over=False
        try:
            with smtp_cls(config['host'],config['port'],timeout=20) as server:
                server.starttls(context=ssl.create_default_context())
                if config.get('user'):server.login(config['user'],config['password'])
                handed_over=True
                server.send_message(msg)
            return
        except Exception as exc:
            if attempt>=RETRY_ATTEMPTS or not _transient(exc,handed_over):raise
            sleep(RETRY_BACKOFF**(attempt-1))
