"""Minimal SMTP mail sender, standard library only (smtplib + email.message).
Used to deliver a hub_token to a customer after self-service signup, since a Stripe
webhook's HTTP response is never seen by the customer. Delivery is best-effort: a failure
here must never undo an already-completed provision() (see service.handle_stripe_event)."""
import os, re, smtplib, ssl
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

def send(to_addr,subject,body,config=None,smtp_cls=smtplib.SMTP):
    config=config or config_from_env()
    if not config.get('host') or not config.get('from_addr'):raise ValueError('SMTP_HOST and SMTP_FROM must be configured')
    if not isinstance(to_addr,str) or not re.fullmatch(r'[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,24}',to_addr):raise ValueError('invalid recipient address')
    msg=EmailMessage();msg['Subject']=subject;msg['From']=config['from_addr'];msg['To']=to_addr;msg.set_content(body)
    with smtp_cls(config['host'],config['port'],timeout=20) as server:
        server.starttls(context=ssl.create_default_context())
        if config.get('user'):server.login(config['user'],config['password'])
        server.send_message(msg)
