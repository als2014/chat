<?php
namespace server\ws\action;
use common\model\ChatModel;
use common\model\FriendApplyModel;
use common\model\UserModel;
use common\model\GroupModel;
class Channel extends Action{
    
    public function handle()
    {
        if($this->data[0] ==="message"){
            $method = $this->data[1];
            $this->$method();
        }
    }

    private function applyCH(){
        //如果申请目标在线,推送全新的好友申请列表
        $applyId = $this->data[2];
        $applyModel = new FriendApplyModel($this->server->db);
        $apply = $applyModel->selectOne(['id' => $applyId]);
        if ($this->server->redis->sIsMember("onlineList", $apply['target_id'])) {
            $targetFd = $this->server->redis->hGet('userId:userFd', $apply['target_id']);
            $applyList = $applyModel->findWithUser(['target_id' => $apply['target_id']]);
            $this->pushApplyList($targetFd, ['applyList' => $applyList,'type'=>'applyList']);
        }
    }
    
    private function agreeCH(){
        //如果申请人在线,推送好友申请被同意消息
        $applyId = $this->data[2];
        $applyModel = new FriendApplyModel($this->server->db);
        $apply = $applyModel->selectOne(['id' => $applyId]);
        if ($this->server->redis->sIsMember("onlineList", $apply['sponsor_id'])) {
            $sponsorFd = $this->server->redis->hGet('userId:userFd',  $apply['sponsor_id']);
            $friend = (new UserModel($this->server->db))->findOne(['id'=>$apply['target_id']]);
            $this->pushAgreeSucc($sponsorFd, ['friend' => $friend]);
        }
    }

    private function closeFD(){
        $closeFd = $this->data[2];
        if ($this->server->exist($closeFd)) {
            //此处有可能消息没发送就关闭了连接
            //todo
            $this->server->push($closeFd, json_encode(['type' => 'repeat']));
            $this->server->close($closeFd);
        }
    }

    private function createGroup(){
        $groupId = $this->data[2];
        $groupModel = new GroupModel($this->server->db);
        $group = $groupModel->findOneWithUser($groupId);
        $time = date("Y-m-d H:i:s");
        $redis = $this->server->redis;
        foreach ($group['userIds'] as $key =>$val ){
            $chatDate = [                      //创建聊天
                'user_id'=>$val['user_id'],
                'target_id'=>$groupId,
                'type'=>ChatModel::TYPE_GROUP,
                'last_chat_time'=>$time
            ];
            $chatModel = new ChatModel($this->server->db);
            $chatId = $chatModel->insert($chatDate);
            if($redis->sIsMember("onlineList",$val['user_id'])){
                $fd  = $redis->hGet("userId:userFd",$val['user_id']);
                $group = $chatModel->findOneWithGroup(['chat_id'=>$chatId])[0];
                $group['msgList'] = [];
                $this->pushNewGroup($fd,['group'=>$group]);
            }
        }
    }
}